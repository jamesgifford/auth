<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use JamesGifford\Auth\Transfers\AccountRoleTransfer;
use JamesGifford\Auth\Transfers\AccountTransfer;
use JamesGifford\Auth\Transfers\UserTransfer;

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
