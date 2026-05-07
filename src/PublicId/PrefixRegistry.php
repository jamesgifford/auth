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

final class PrefixRegistry
{
    /**
     * @var array<string, string>  modelClass => prefix
     */
    private array $registered = [];

    public function __construct(private readonly PublicIdConfig $config)
    {
    }

    public function prefixFor(string $modelClass): string
    {
        if (isset($this->registered[$modelClass])) {
            return $this->registered[$modelClass];
        }

        $prefix = $this->resolvePrefix($modelClass);
        $this->registered[$modelClass] = $prefix;

        return $prefix;
    }

    public function modelFor(string $prefix): ?string
    {
        foreach ($this->registered as $modelClass => $registeredPrefix) {
            if ($registeredPrefix === $prefix) {
                return $modelClass;
            }
        }

        return null;
    }

    public function register(string $modelClass): void
    {
        if (isset($this->registered[$modelClass])) {
            return;
        }

        $this->registered[$modelClass] = $this->resolvePrefix($modelClass);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->registered;
    }

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
                    throw new InvalidPrefixException(sprintf(
                        "Invalid public_id prefix returned by %s::publicIdPrefix(): must be 1 to %d lowercase ASCII letters, got %s.",
                        $modelClass,
                        $this->config->prefixMaxLength(),
                        is_string($prefix) ? "'{$prefix}'" : gettype($prefix),
                    ));
                }

                return $prefix;
            }
        }

        $configPrefixes = $this->config->prefixes();
        if (array_key_exists($modelClass, $configPrefixes)) {
            return $configPrefixes[$modelClass];
        }

        throw new UnregisteredModelException(sprintf(
            "Model '%s' has no public_id prefix. Either implement publicIdPrefix() on the model or add it to config('progravity.auth.public_id.prefixes').",
            $modelClass,
        ));
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
