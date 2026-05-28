<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown by AccountService::attachUser() when the user already has a
 * membership in the account. Steers callers toward changeRole() for the
 * common "I wanted to update their role" case.
 */
class AlreadyAMemberException extends RuntimeException
{
    public static function forUserAndAccount(string $userPublicId, string $accountPublicId): self
    {
        return new self(
            "User '{$userPublicId}' is already a member of account '{$accountPublicId}'. ".
            'Use changeRole() to update an existing membership\'s role.'
        );
    }
}
