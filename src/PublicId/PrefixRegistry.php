<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId;

use Progravity\Auth\PublicId\Concerns\HasPublicId;
use Progravity\Auth\PublicId\Config\PublicIdConfig;
use Progravity\Auth\PublicId\Exceptions\InvalidPrefixException;
use Progravity\Auth\PublicId\Exceptions\PrefixCollisionException;
use Progravity\Auth\PublicId\Exceptions\UnregisteredModelException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tracks which model classes claim which public_id prefixes.
 *
 * Resolution order for a given model:
 *   1. If the model overrides publicIdPrefix(), call it (NOT the trait's
 *      default — that would recurse back into the registry)
 *   2. Otherwise, look the model up in config('progravity.auth.public_id.prefixes')
 *   3. Otherwise, throw {@see UnregisteredModelException}
 *
 * Resolution is lazy and cached — first call resolves and stores; subsequent
 * calls return the cached prefix.
 */
final class PrefixRegistry
{
    /**
     * @var array<string, string>  modelClass => prefix
     */
    private array $registered = [];

    public function __construct(private readonly PublicIdConfig $config)
    {
    }

    /**
     * Return the prefix claimed by the given model class.
     *
     * @throws InvalidPrefixException     if the model's publicIdPrefix() returns
     *                                    a value that isn't 1+ lowercase ASCII letters
     *                                    within prefix_max_length
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
     */
    public function modelFor(string $prefix): ?string
    {
        foreach ($this->registered as $modelClass => $registeredPrefix) {
            if ($registeredPrefix === $prefix) {
                return $modelClass;
            }
        }

        return null;
    }

    /**
     * Eagerly register a model and cache its resolved prefix. Idempotent.
     *
     * @throws InvalidPrefixException     see {@see prefixFor()}
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
     * Throws if two registered models claim the same prefix.
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
            if (count($modelClasses) > 1) {
                throw PrefixCollisionException::forPrefix($prefix, $modelClasses);
            }
        }
    }

    private function resolvePrefix(string $modelClass): string
    {
        $reflection = new ReflectionClass($modelClass);

        if ($reflection->hasMethod('publicIdPrefix')) {
            $method = $reflection->getMethod('publicIdPrefix');
            if (! $this->isTraitDefault($method)) {
                $instance = new $modelClass;
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
