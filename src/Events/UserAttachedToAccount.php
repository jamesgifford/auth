<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use JamesGifford\Auth\Transfers\AccountRoleTransfer;
use JamesGifford\Auth\Transfers\AccountTransfer;
use JamesGifford\Auth\Transfers\MembershipTransfer;
use JamesGifford\Auth\Transfers\UserTransfer;

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
