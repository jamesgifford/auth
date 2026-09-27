<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Accounts\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JamesGifford\Auth\Concerns\HasAccounts;
use JamesGifford\Auth\Events\CurrentAccountChanged;
use JamesGifford\Auth\Http\Middleware\EnsureCurrentAccount;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\PackageModels;
use JamesGifford\Auth\Transfers\AccountTransfer;
use JamesGifford\Auth\Transfers\UserTransfer;

/**
 * The one place the package writes a user's current account.
 *
 * Every path that changes `current_account_id` goes through here —
 * {@see HasAccounts::switchToAccount()}, {@see AccountService::detachUser()},
 * {@see AccountService::delete()}, {@see AccountService::forceDelete()}, and
 * the {@see EnsureCurrentAccount} middleware — so each gets the same two
 * guarantees:
 *
 *  - the user instance's cached `currentAccount` relation follows the new value
 *    immediately; a stale relation would keep scoping the rest of the request
 *    to the old account; and
 *  - {@see CurrentAccountChanged} is dispatched after commit, exactly when the
 *    value actually changes.
 *
 * It does not check membership. Callers either validated it already
 * (switchToAccount()) or are clearing a pointer to an account the user can no
 * longer use.
 */
final class CurrentAccountService
{
    /**
     * Point the user at $account (null clears it) and persist.
     *
     * Setting the account that is already current only refreshes the cached
     * relation: no save (so no model events on every request that revalidates
     * an unchanged account) and no event. Writing the same value was never a
     * database change anyway — Eloquent skips clean attributes.
     *
     * @param  Model  $user  The consumer's User model (config-resolved class).
     */
    public function set(Model $user, ?Account $account): void
    {
        $previousId = $this->idOf($user->getAttribute('current_account_id'));
        $newId = $this->idOf($account?->getKey());

        if ($previousId === $newId) {
            $user->setRelation('currentAccount', $account);

            return;
        }

        // Resolve the outgoing account before the relation is overwritten.
        $previous = $previousId === null ? null : $this->loadPrevious($user, $previousId);

        $user->setAttribute('current_account_id', $newId);
        $user->save();
        $user->setRelation('currentAccount', $account);

        $this->dispatch(
            UserTransfer::fromModel($user),
            $previous === null ? null : AccountTransfer::fromModel($previous),
            $account === null ? null : AccountTransfer::fromModel($account),
        );
    }

    /**
     * Clear the current account of every user it is set to $account for, with
     * one bulk update (no per-user model events) and one event per user.
     *
     * Only the rows change: user instances already in memory elsewhere keep
     * their old value until they are reloaded. The EnsureCurrentAccount
     * middleware revalidates on the next request.
     */
    public function clearForAccount(Account $account): void
    {
        $holders = PackageModels::user()::query()
            ->where('current_account_id', $account->getKey())
            ->get();

        if ($holders->isEmpty()) {
            return;
        }

        PackageModels::user()::query()
            ->where('current_account_id', $account->getKey())
            ->update(['current_account_id' => null]);

        // Snapshot now: a force-deleted account is gone by the time
        // the after-commit callback runs.
        $previous = AccountTransfer::fromModel($account);

        foreach ($holders as $holder) {
            $this->dispatch(UserTransfer::fromModel($holder), $previous, null);
        }
    }

    /**
     * The account the user is moving away from. Uses the cached relation when
     * it matches; otherwise loads it, including a soft-deleted one (the case
     * the middleware repairs), so listeners still learn what it was.
     */
    private function loadPrevious(Model $user, int $previousId): ?Account
    {
        $cached = $user->relationLoaded('currentAccount') ? $user->getRelation('currentAccount') : null;

        if ($cached instanceof Account && $this->idOf($cached->getKey()) === $previousId) {
            return $cached;
        }

        /** @var Account|null */
        return PackageModels::account()::query()->withTrashed()->find($previousId);
    }

    private function dispatch(UserTransfer $user, ?AccountTransfer $previous, ?AccountTransfer $new): void
    {
        DB::afterCommit(static function () use ($user, $previous, $new): void {
            CurrentAccountChanged::dispatch($user, $previous, $new);
        });
    }

    private function idOf(mixed $key): ?int
    {
        return $key === null ? null : (int) $key;
    }
}
