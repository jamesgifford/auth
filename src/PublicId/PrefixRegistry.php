<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId;

use JamesGifford\Auth\PublicId\Concerns\HasPublicId;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Exceptions\InvalidPrefixException;
use JamesGifford\Auth\PublicId\Exceptions\PrefixCollisionException;
use JamesGifford\Auth\PublicId\Exceptions\UnregisteredModelException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tracks which model classes claim which public_id prefixes.
 *
 * Resolution order for a given model:
 *   1. If the model overrides publicIdPrefix(), call it (NOT the trait's
 *      default — that would recurse back into the registry)
 *   2. Otherwise, look the model up in config('jamesgifford.auth.public_id.prefixes')
 *   3. Otherwise, throw {@see UnregisteredModelException}
 *
 * Resolution is lazy and cached — first call resolves and stores; subsequent
 * calls return the cached prefix.
 *
 * Same-prefix claims within a single inheritance chain are ONE logical
 * registration, not a collision: a published App\Models subclass inherits its
 * parent's publicIdPrefix(), so after the standard publish-models flow both
 * the package base model (registered from the config prefixes map) and the
 * consumer's subclass (self-registered on boot) legitimately claim the same
 * prefix. Only unrelated classes claiming one prefix collide, and
 * {@see modelFor()} resolves a chain to its most-derived registered class —
 * the consumer's effective model.
 */
final class PrefixRegistry
{
    /**
     * @var array<string, string> modelClass => prefix
     */
    private array $registered = [];

    public function __construct(private readonly PublicIdConfig $config) {}

    /**
     * Return the prefix claimed by the given model class.
     *
     * @throws InvalidPrefixException if the model's publicIdPrefix() returns
     *                                a value that isn't 1+ lowercase ASCII letters
     *                                within prefix_max_length
     * @throws UnregisteredModelException if the model has no override and no config entry
     */
    public function prefixFor(string $modelClass): string
    {
        if (isset($this->registered[$modelClass])) {
            return $this->registered[$modelClass];
        }

        $prefix = $this->resolvePrefix($modelClass);
        $this->registered[$modelClass] = $prefix;

        return $prefix;
    }

    /**
     * Reverse lookup: which model class (if any) claims this prefix.
     * Walks only the already-registered set; will not lazily resolve.
     * When a base class and its subclass both claim the prefix (one
     * inheritance chain), the most-derived registered class wins.
     */
    public function modelFor(string $prefix): ?string
    {
        $match = null;

        foreach ($this->registered as $modelClass => $registeredPrefix) {
            if ($registeredPrefix !== $prefix) {
                continue;
            }

            if ($match === null || is_subclass_of($modelClass, $match)) {
                $match = $modelClass;
            }
        }

        return $match;
    }

    /**
     * Eagerly register a model and cache its resolved prefix. Idempotent.
     *
     * @throws InvalidPrefixException see {@see prefixFor()}
     * @throws UnregisteredModelException see {@see prefixFor()}
     */
    public function register(string $modelClass): void
    {
        if (isset($this->registered[$modelClass])) {
            return;
        }

        $this->registered[$modelClass] = $this->resolvePrefix($modelClass);
    }

    /**
     * All registered prefixes as `[modelClass => prefix]`.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->registered;
    }

    /**
     * Throws if two UNRELATED registered models claim the same prefix. A
     * group forming a single inheritance chain (base + subclasses inheriting
     * its prefix) is one logical claim, never a collision.
     *
     * @throws PrefixCollisionException
     */
    public function assertNoCollisions(): void
    {
        $byPrefix = [];
        foreach ($this->registered as $modelClass => $prefix) {
            $byPrefix[$prefix][] = $modelClass;
        }

        foreach ($byPrefix as $prefix => $modelClasses) {
            if (count($modelClasses) > 1 && ! $this->isSingleInheritanceChain($modelClasses)) {
                throw PrefixCollisionException::forPrefix($prefix, $modelClasses);
            }
        }
    }

    /**
     * Whether every pair of classes is related by inheritance. In a class
     * hierarchy, pairwise relatedness implies one linear ancestor chain —
     * two sibling subclasses claiming the same prefix are pairwise unrelated
     * and therefore a genuine collision.
     *
     * @param  list<string>  $classes
     */
    private function isSingleInheritanceChain(array $classes): bool
    {
        foreach ($classes as $a) {
            foreach ($classes as $b) {
                if ($a !== $b && ! is_subclass_of($a, $b) && ! is_subclass_of($b, $a)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function resolvePrefix(string $modelClass): string
    {
        $reflection = new ReflectionClass($modelClass);

        if ($reflection->hasMethod('publicIdPrefix')) {
            $method = $reflection->getMethod('publicIdPrefix');
            if (! $this->isTraitDefault($method)) {
                // Instantiate WITHOUT the constructor so resolving a prefix
                // never triggers the model's boot sequence. The eager
                // register() call happens during bootHasPublicId(); a plain
                // `new $modelClass` would re-enter boot, which Laravel 13's
                // Model::bootIfNotBooted() rejects with a LogicException.
                // publicIdPrefix() returns a constant string and needs no
                // constructor state, so a constructor-less instance is safe.
                $instance = $reflection->newInstanceWithoutConstructor();
                $prefix = $instance->publicIdPrefix();

                if (! is_string($prefix) || preg_match('/^[a-z]+$/', $prefix) !== 1
                    || strlen($prefix) > $this->config->prefixMaxLength()) {
                    throw InvalidPrefixException::forModelMethod(
                        $modelClass,
                        $prefix,
                        $this->config->prefixMaxLength(),
                    );
                }

                return $prefix;
            }
        }

        $configPrefixes = $this->config->prefixes();
        if (array_key_exists($modelClass, $configPrefixes)) {
            return $configPrefixes[$modelClass];
        }

        throw UnregisteredModelException::forModel($modelClass);
    }

    private function isTraitDefault(ReflectionMethod $method): bool
    {
        if (! trait_exists(HasPublicId::class)) {
            return false;
        }

        $traitMethod = new ReflectionMethod(HasPublicId::class, 'publicIdPrefix');

        return $method->getFileName() === $traitMethod->getFileName()
            && $method->getStartLine() === $traitMethod->getStartLine();
    }
}
