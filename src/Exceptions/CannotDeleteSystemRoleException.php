<?php

declare(strict_types=1);

namespace Progravity\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when an attempt is made to delete a system AccountRole via Eloquent.
 *
 * System roles ship with the package and are seeded from
 * config('progravity.auth.roles'). They cannot be removed at runtime; the
 * 'owner' role in particular is required and cannot be removed under any
 * circumstances. Use {@see forRole()} so the message names the offending key.
 */
class CannotDeleteSystemRoleException extends RuntimeException
{
    public static function forRole(string $key): self
    {
        return new self(
            "Cannot delete system role '{$key}'. System roles ship with the package and ".
            'cannot be removed via Eloquent. To customize which roles exist, edit '.
            "config('progravity.auth.roles') and re-run the AccountRoleSeeder. Note that ".
            "the 'owner' role is required and cannot be removed under any circumstances."
        );
    }
}
