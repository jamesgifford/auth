<?php

declare(strict_types=1);

namespace Progravity\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Progravity\Auth\Transfers\AccountRoleTransfer;
use Progravity\Auth\Transfers\AccountTransfer;
use Progravity\Auth\Transfers\MembershipTransfer;
use Progravity\Auth\Transfers\UserTransfer;

/**
 * Dispatched after AccountService::attachUser() commits.
 */
final class UserAttachedToAccount
{
    use Dispatchable;

    public function __construct(
        public readonly AccountTransfer $account,
        public readonly UserTransfer $user,
        public readonly AccountRoleTransfer $role,
        public readonly MembershipTransfer $membership,
    ) {}
}
