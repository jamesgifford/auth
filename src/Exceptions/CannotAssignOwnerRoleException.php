<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Exceptions;

use JamesGifford\Auth\Accounts\Services\AccountService;
use RuntimeException;

/**
 * Thrown when a caller tries to assign the 'owner' role through any path
 * other than {@see AccountService::create()}
 * or transferOwnership(). The Owner role is bound to a specific user per
 * account; assigning it via attachUser/changeRole would create the
 * possibility of two owners.
 *
 * `$context` is a short string naming the method that was called
 * (e.g. 'attachUser', 'changeRole') so the error points at the call site.
 */
class CannotAssignOwnerRoleException extends RuntimeException
{
    public static function forContext(string $context): self
    {
        return new self(
            "Cannot assign the 'owner' role via {$context}. ".
            'Use AccountService::create() to create an account with an owner, '.
            "or AccountService::transferOwnership() to change an existing account's owner."
        );
    }
}
