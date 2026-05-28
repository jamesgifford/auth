<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use JamesGifford\Auth\Transfers\AccountRoleTransfer;
use JamesGifford\Auth\Transfers\AccountTransfer;
use JamesGifford\Auth\Transfers\UserTransfer;

/**
 * Dispatched after AccountService::changeRole() commits with a real change.
 *
 * Not dispatched when the new role equals the current role (no-op skip).
 */
final class AccountRoleChanged
{
    use Dispatchable;

    public function __construct(
        public readonly AccountTransfer $account,
        public readonly UserTransfer $user,
        public readonly AccountRoleTransfer $previousRole,
        public readonly AccountRoleTransfer $newRole,
    ) {}
}
