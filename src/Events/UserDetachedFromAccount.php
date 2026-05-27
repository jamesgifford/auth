<?php

declare(strict_types=1);

namespace Progravity\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Progravity\Auth\Transfers\AccountRoleTransfer;
use Progravity\Auth\Transfers\AccountTransfer;
use Progravity\Auth\Transfers\UserTransfer;

/**
 * Dispatched after AccountService::detachUser() commits.
 *
 * `previousRole` is captured before the membership is deleted; listeners
 * have no other way to know what role the user held at detach time.
 */
final class UserDetachedFromAccount
{
    use Dispatchable;

    public function __construct(
        public readonly AccountTransfer $account,
        public readonly UserTransfer $user,
        public readonly AccountRoleTransfer $previousRole,
    ) {}
}
