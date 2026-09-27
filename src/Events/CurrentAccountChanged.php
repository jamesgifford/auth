<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Concerns\HasAccounts;
use JamesGifford\Auth\Http\Middleware\EnsureCurrentAccount;
use JamesGifford\Auth\Transfers\AccountTransfer;
use JamesGifford\Auth\Transfers\UserTransfer;

/**
 * Dispatched after a user's current account actually changes — never when it
 * is set to the account it already was.
 *
 * Fired by {@see HasAccounts::switchToAccount()},
 * {@see AccountService::detachUser()} (when the user is removed from their
 * current account), {@see AccountService::delete()} and
 * {@see AccountService::forceDelete()} (once per user the account was current
 * for), and {@see EnsureCurrentAccount} when it clears or reassigns a missing
 * or invalid current account. Like the package's other events it waits for
 * the surrounding transaction to commit, and never fires on a rollback.
 *
 * `previousAccount` is null when the user had no current account (their first
 * switch, e.g. at registration); `newAccount` is null when the current account
 * was cleared. A previous account that has since been soft-deleted is still
 * reported.
 */
final class CurrentAccountChanged
{
    use Dispatchable;

    public function __construct(
        public readonly UserTransfer $user,
        public readonly ?AccountTransfer $previousAccount,
        public readonly ?AccountTransfer $newAccount,
    ) {}
}
