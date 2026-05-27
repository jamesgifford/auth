<?php

declare(strict_types=1);

namespace Progravity\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown by AccountService::changeRole() when the target user is the
 * account's current Owner. Demoting an owner directly would leave the
 * account ownerless; transferOwnership() is the supported path.
 */
class CannotModifyOwnerRoleException extends RuntimeException
{
    public static function forUserAndAccount(string $userPublicId, string $accountPublicId): self
    {
        return new self(
            "Cannot change the Owner's role on account '{$accountPublicId}'. ".
            'Use transferOwnership() to assign ownership to another user. '.
            "(Current owner: '{$userPublicId}')"
        );
    }
}
