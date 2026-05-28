<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use JamesGifford\Auth\Transfers\AccountTransfer;
use JamesGifford\Auth\Transfers\MembershipTransfer;
use JamesGifford\Auth\Transfers\UserTransfer;

/**
 * Dispatched after AccountService::create() commits.
 *
 * Carries snapshots of the account, the owner, and the Owner-role membership
 * row created alongside it. Listeners run after the transaction commits, so
 * the records are guaranteed to be persisted.
 */
final class AccountCreated
{
    use Dispatchable;

    public function __construct(
        public readonly AccountTransfer $account,
        public readonly UserTransfer $owner,
        public readonly MembershipTransfer $ownerMembership,
    ) {}
}
