<?php

declare(strict_types=1);

namespace Progravity\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when an Owner-dependent operation encounters an account whose
 * Owner invariant is broken (no Owner membership for the recorded owner_id,
 * mismatched ids, etc.). Always indicates data corruption: the service layer
 * normally maintains these invariants, so reaching this means external writes
 * have happened or a prior operation was interrupted past its transaction.
 */
class OwnerlessAccountException extends RuntimeException
{
    public static function forAccount(string $accountPublicId): self
    {
        return new self(
            "Account '{$accountPublicId}' has no valid Owner. This indicates data corruption. ".
            'Run `php artisan progravity:auth:check-integrity` (once available) or use '.
            'AccountIntegrityService::scan() to investigate.'
        );
    }
}
