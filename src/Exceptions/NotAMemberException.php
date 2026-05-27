<?php

declare(strict_types=1);

namespace Progravity\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation requires a user to be a member of an account but
 * they are not. The canonical case is {@see \Progravity\Auth\Concerns\HasAccounts::switchToAccount()},
 * which refuses to point a user's current_account_id at an account they have
 * no membership in. Use {@see forUserAndAccount()} so the message names both
 * public_ids and the misuse is loud in logs.
 */
class NotAMemberException extends RuntimeException
{
    public static function forUserAndAccount(string $userPublicId, string $accountPublicId): self
    {
        return new self(
            "User '{$userPublicId}' is not a member of account '{$accountPublicId}'."
        );
    }
}
