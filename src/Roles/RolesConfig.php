<?php

declare(strict_types=1);

namespace Progravity\Auth\Roles;

use InvalidArgumentException;
use Progravity\Auth\Exceptions\InvalidRolesConfigException;
use Progravity\Auth\SystemRole;

/**
 * Typed wrapper over the raw `progravity.auth.roles` config array.
 *
 * Validates the array eagerly at construction so misconfiguration surfaces
 * at first resolution rather than at seed time. The 'owner' role is required
 * and must be a system role; any key that matches a reserved system role name
 * (owner, admin, member, viewer) must also be declared system => true so
 * consumers cannot accidentally redefine a system role as custom.
 *
 * Constructed by the service provider from `config('progravity.auth.roles')`.
 */
final class RolesConfig
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** @var array<string, array<string, mixed>> */
    private readonly array $roles;

    /**
     * @param  array<mixed, mixed>  $roles  the `roles` config subarray
     *
     * @throws InvalidRolesConfigException on any validation failure
     */
    public function __construct(array $roles)
    {
        if ($roles === []) {
            throw InvalidRolesConfigException::general('must be a non-empty array of role definitions.');
        }

        $reserved = SystemRole::all();

        foreach ($roles as $key => $attributes) {
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw InvalidRolesConfigException::forKey(
                    is_string($key) ? $key : (string) $key,
                    'role keys must be lowercase and start with a letter, using only letters, digits, and underscores.'
                );
            }

            if (! is_array($attributes)) {
                throw InvalidRolesConfigException::forKey($key, 'each role definition must be an array.');
            }

            if (! array_key_exists('name', $attributes) || ! is_string($attributes['name']) || $attributes['name'] === '') {
                throw InvalidRolesConfigException::forKey($key, "'name' is required and must be a non-empty string.");
            }

            if (! array_key_exists('system', $attributes) || ! is_bool($attributes['system'])) {
                throw InvalidRolesConfigException::forKey($key, "'system' is required and must be a boolean.");
            }

            if (array_key_exists('description', $attributes)
                && $attributes['description'] !== null
                && ! is_string($attributes['description'])) {
                throw InvalidRolesConfigException::forKey($key, "'description' must be a string or null.");
            }

            if (array_key_exists('sort_order', $attributes) && ! is_int($attributes['sort_order'])) {
                throw InvalidRolesConfigException::forKey($key, "'sort_order' must be an integer.");
            }

            if (in_array($key, $reserved, true) && $attributes['system'] !== true) {
                throw InvalidRolesConfigException::forKey(
                    $key,
                    "'{$key}' is a reserved system role key and must be declared with system => true."
                );
            }
        }

        if (! array_key_exists(SystemRole::OWNER, $roles)) {
            throw InvalidRolesConfigException::forKey(
                SystemRole::OWNER,
                'the owner role is required and must be present in the roles config.'
            );
        }

        /** @var array<string, array<string, mixed>> $roles */
        $this->roles = $roles;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $key): bool
    {
        return array_key_exists($key, $this->roles);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        if (! $this->hasRole($key)) {
            throw new InvalidArgumentException("No role configured with key '{$key}'.");
        }

        return $this->roles[$key];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function systemRoles(): array
    {
        return array_filter($this->roles, fn (array $role): bool => ($role['system'] ?? false) === true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function customRoles(): array
    {
        return array_filter($this->roles, fn (array $role): bool => ($role['system'] ?? false) !== true);
    }
}
