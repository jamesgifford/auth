<?php

declare(strict_types=1);

namespace Progravity\Auth\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an operation references a role key that is not present in
 * the account_roles table (and, by extension, not in config). Use
 * {@see forKey()} so the message names the offending key.
 */
class InvalidRoleException extends InvalidArgumentException
{
    public static function forKey(string $roleKey): self
    {
        return new self(
            "No role exists with key '{$roleKey}'. ".
            "Available roles are configured in config('progravity.auth.roles')."
        );
    }
}
