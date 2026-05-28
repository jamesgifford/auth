<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown by AccountService::detachUser() when the target user is the
 * account's current Owner. Detaching the owner would leave the account
 * ownerless and violate the single-owner invariant.
 */
class CannotDetachOwnerException extends RuntimeException
{
    public static function forUserAndAccount(string $userPublicId, string $accountPublicId): self
    {
        return new self(
            "Cannot detach the Owner from account '{$accountPublicId}'. ".
            'Transfer ownership to another user first, then detach. '.
            "(Current owner: '{$userPublicId}')"
        );
    }
}
