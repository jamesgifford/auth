<?php

declare(strict_types=1);

namespace Progravity\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Progravity\Auth\Transfers\AccountRoleTransfer;
use Progravity\Auth\Transfers\AccountTransfer;
use Progravity\Auth\Transfers\UserTransfer;

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
