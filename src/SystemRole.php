<?php

declare(strict_types=1);

namespace Progravity\Auth;

/**
 * Constants for the system roles shipped with the package.
 *
 * These keys match entries in the package's default config at
 * config('progravity.auth.roles'). The config is the source of truth;
 * this class exists as a typo-prevention layer for internal references.
 *
 * Consumers may use these constants when referencing system roles in code:
 *
 *     $user->hasRole($account, SystemRole::OWNER);
 *
 * For consumer-added roles, use string keys directly:
 *
 *     $user->hasRole($account, 'auditor');
 */
final class SystemRole
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const MEMBER = 'member';

    public const VIEWER = 'viewer';

    private function __construct()
    {
        // Prevent instantiation
    }

    /**
     * Returns all system role keys.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::OWNER,
            self::ADMIN,
            self::MEMBER,
            self::VIEWER,
        ];
    }
}
