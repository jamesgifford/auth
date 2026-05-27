<?php

declare(strict_types=1);

namespace Progravity\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown by AccountService::transferOwnership() when the proposed new owner
 * is already the account's current owner. Always a caller bug — the operation
 * would be a no-op and the demotion semantics make no sense applied to the
 * same user.
 */
class SelfOwnershipTransferException extends RuntimeException
{
    public static function forAccount(string $accountPublicId): self
    {
        return new self(
            "Cannot transfer ownership of account '{$accountPublicId}' to its current owner."
        );
    }
}
