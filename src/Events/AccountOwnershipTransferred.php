<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use JamesGifford\Auth\Transfers\AccountRoleTransfer;
use JamesGifford\Auth\Transfers\AccountTransfer;
use JamesGifford\Auth\Transfers\UserTransfer;

/**
 * Dispatched after AccountService::transferOwnership() commits.
 *
 * `previousOwnerNewRole` is the role the previous owner was demoted to
 * (default Admin, configurable per call). Listeners typically log both
 * users and the demotion target for audit trails.
 */
final class AccountOwnershipTransferred
{
    use Dispatchable;

    public function __construct(
        public readonly AccountTransfer $account,
        public readonly UserTransfer $previousOwner,
        public readonly UserTransfer $newOwner,
        public readonly AccountRoleTransfer $previousOwnerNewRole,
    ) {}
}
