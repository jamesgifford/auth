<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Accounts\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JamesGifford\Auth\Events\AccountCreated;
use JamesGifford\Auth\Events\AccountDeleted;
use JamesGifford\Auth\Events\AccountForceDeleted;
use JamesGifford\Auth\Events\AccountOwnershipTransferred;
use JamesGifford\Auth\Events\AccountRestored;
use JamesGifford\Auth\Events\AccountRoleChanged;
use JamesGifford\Auth\Events\UserAttachedToAccount;
use JamesGifford\Auth\Events\UserDetachedFromAccount;
use JamesGifford\Auth\Exceptions\AlreadyAMemberException;
use JamesGifford\Auth\Exceptions\CannotAssignOwnerRoleException;
use JamesGifford\Auth\Exceptions\CannotDetachOwnerException;
use JamesGifford\Auth\Exceptions\CannotModifyOwnerRoleException;
use JamesGifford\Auth\Exceptions\InvalidRoleException;
use JamesGifford\Auth\Exceptions\NotAMemberException;
use JamesGifford\Auth\Exceptions\OwnerlessAccountException;
use JamesGifford\Auth\Exceptions\SelfOwnershipTransferException;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Roles\RolesConfig;
use JamesGifford\Auth\SystemRole;
use JamesGifford\Auth\Transfers;
use JamesGifford\Auth\Transfers\AccountRoleTransfer;
use JamesGifford\Auth\Transfers\AccountTransfer;
use JamesGifford\Auth\Transfers\MembershipTransfer;
use JamesGifford\Auth\Transfers\UserTransfer;

/**
 * Service layer for account and membership operations.
 *
 * All mutating methods wrap their database work in a transaction and queue
 * event dispatch via DB::afterCommit() so listeners never fire on a rolled
 * back transaction. Events carry readonly transfers rather than live model
 * references — see {@see Transfers}.
 *
 * Methods take the consumer's User model as an Illuminate Model because the
 * concrete class is configured via config('jamesgifford.auth.models.user').
 * The package does not know the FQCN at compile time.
 */
final class AccountService
{
    public function __construct(
        private readonly RolesConfig $rolesConfig,
    ) {}

    /**
     * Create an account owned by the given user and seed the Owner membership.
     *
     * @param  Model  $owner  The consumer's User model (config-resolved class).
     * @param  string|null  $name  Account name. When null, resolved from
     *                             config('jamesgifford.auth.accounts.default_name_template')
     *                             with {name} substituted by the owner's name.
     *
     * Side effects:
     *  - Inserts an `accounts` row and a matching `account_user` row in a
     *    single transaction.
     *  - Dispatches {@see AccountCreated} after the transaction commits.
     *
     * @throws InvalidRoleException When the Owner role is missing from the
     *                              database (seeder not run).
     */
    public function create(Model $owner, ?string $name = null): Account
    {
        $accountName = $name ?? $this->renderDefaultName($owner);
        $ownerRole = $this->requireRole(SystemRole::OWNER);

        /** @var class-string<Account> $accountClass */
        $accountClass = config('jamesgifford.auth.models.account');

        $account = DB::transaction(function () use ($accountClass, $owner, $accountName, $ownerRole) {
            /** @var Account $account */
            $account = $accountClass::query()->create([
                'name' => $accountName,
                'owner_id' => $owner->id,
            ]);

            $membership = AccountUser::query()->create([
                'account_id' => $account->id,
                'user_id' => $owner->id,
                'account_role_id' => $ownerRole->id,
                'joined_at' => now(),
            ]);

            DB::afterCommit(function () use ($account, $owner, $membership): void {
                AccountCreated::dispatch(
                    AccountTransfer::fromModel($account),
                    UserTransfer::fromModel($owner),
                    MembershipTransfer::fromModel($membership),
                );
            });

            return $account;
        });

        return $account->load(['owner', 'memberships.role']);
    }

    /**
     * Attach a user to an account with the given role.
     *
     * Side effects:
     *  - Inserts an `account_user` row.
     *  - Dispatches {@see UserAttachedToAccount} after commit.
     *
     * @throws CannotAssignOwnerRoleException When $roleKey is 'owner'.
     * @throws InvalidRoleException When $roleKey is not a configured role.
     * @throws AlreadyAMemberException When the user already has a membership.
     */
    public function attachUser(Account $account, Model $user, string $roleKey): AccountUser
    {
        if ($roleKey === SystemRole::OWNER) {
            throw CannotAssignOwnerRoleException::forContext('attachUser');
        }

        $role = $this->requireRole($roleKey);

        $existing = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($existing) {
            throw AlreadyAMemberException::forUserAndAccount(
                $this->userIdentifier($user),
                $account->public_id,
            );
        }

        $membership = DB::transaction(function () use ($account, $user, $role) {
            $membership = AccountUser::query()->create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'account_role_id' => $role->id,
                'joined_at' => now(),
            ]);

            DB::afterCommit(function () use ($account, $user, $role, $membership): void {
                UserAttachedToAccount::dispatch(
                    AccountTransfer::fromModel($account),
                    UserTransfer::fromModel($user),
                    AccountRoleTransfer::fromModel($role),
                    MembershipTransfer::fromModel($membership),
                );
            });

            return $membership;
        });

        return $membership->load('role');
    }

    /**
     * Detach a user from an account.
     *
     * Side effects:
     *  - Deletes the `account_user` row.
     *  - Nulls the user's `current_account_id` when it points at the
     *    account they're being detached from (so the user doesn't end up
     *    pointing at an inaccessible account).
     *  - Dispatches {@see UserDetachedFromAccount} after commit.
     *
     * @throws NotAMemberException When the user has no membership in the account.
     * @throws CannotDetachOwnerException When the user is the account's Owner.
     */
    public function detachUser(Account $account, Model $user): void
    {
        $membership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $user->id)
            ->with('role')
            ->first();

        if ($membership === null) {
            throw NotAMemberException::forUserAndAccount(
                $this->userIdentifier($user),
                $account->public_id,
            );
        }

        if ($membership->isOwner()) {
            throw CannotDetachOwnerException::forUserAndAccount(
                $this->userIdentifier($user),
                $account->public_id,
            );
        }

        $previousRole = $membership->role;

        DB::transaction(function () use ($account, $user, $membership, $previousRole): void {
            $membership->delete();

            if ($user->current_account_id === $account->id) {
                $user->current_account_id = null;
                $user->save();
            }

            DB::afterCommit(function () use ($account, $user, $previousRole): void {
                UserDetachedFromAccount::dispatch(
                    AccountTransfer::fromModel($account),
                    UserTransfer::fromModel($user),
                    AccountRoleTransfer::fromModel($previousRole),
                );
            });
        });
    }

    /**
     * Change a user's role within an account.
     *
     * No-op (and no event) when the requested role equals the current role.
     *
     * Side effects:
     *  - Updates the `account_user.account_role_id` column.
     *  - Dispatches {@see AccountRoleChanged} after commit (only on real changes).
     *
     * @throws CannotAssignOwnerRoleException When $newRoleKey is 'owner'.
     * @throws InvalidRoleException When $newRoleKey is not a configured role.
     * @throws NotAMemberException When the user has no membership in the account.
     * @throws CannotModifyOwnerRoleException When the user is the account's Owner.
     */
    public function changeRole(Account $account, Model $user, string $newRoleKey): AccountUser
    {
        if ($newRoleKey === SystemRole::OWNER) {
            throw CannotAssignOwnerRoleException::forContext('changeRole');
        }

        $newRole = $this->requireRole($newRoleKey);

        $membership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $user->id)
            ->with('role')
            ->first();

        if ($membership === null) {
            throw NotAMemberException::forUserAndAccount(
                $this->userIdentifier($user),
                $account->public_id,
            );
        }

        if ($membership->isOwner()) {
            throw CannotModifyOwnerRoleException::forUserAndAccount(
                $this->userIdentifier($user),
                $account->public_id,
            );
        }

        $previousRole = $membership->role;

        if ($previousRole->id === $newRole->id) {
            return $membership;
        }

        return DB::transaction(function () use ($account, $user, $membership, $previousRole, $newRole) {
            $membership->update(['account_role_id' => $newRole->id]);
            $membership->setRelation('role', $newRole);

            DB::afterCommit(function () use ($account, $user, $previousRole, $newRole): void {
                AccountRoleChanged::dispatch(
                    AccountTransfer::fromModel($account),
                    UserTransfer::fromModel($user),
                    AccountRoleTransfer::fromModel($previousRole),
                    AccountRoleTransfer::fromModel($newRole),
                );
            });

            return $membership;
        });
    }

    /**
     * Atomically transfer ownership of an account from its current owner to
     * another existing member.
     *
     * The three updates (previous owner's role, new owner's role, and
     * accounts.owner_id) all run in a single transaction; no intermediate
     * "two owners" or "zero owners" state is ever visible to readers outside
     * the transaction.
     *
     * @param  string  $previousOwnerNewRoleKey  Role the previous owner is
     *                                           demoted to. Defaults to Admin.
     *
     * Side effects:
     *  - Updates both memberships' account_role_id and the accounts.owner_id column.
     *  - Dispatches {@see AccountOwnershipTransferred} after commit.
     *
     * @throws CannotAssignOwnerRoleException When $previousOwnerNewRoleKey is 'owner'.
     * @throws InvalidRoleException When $previousOwnerNewRoleKey is not configured.
     * @throws SelfOwnershipTransferException When $newOwner is already the owner.
     * @throws NotAMemberException When $newOwner is not already a member.
     * @throws OwnerlessAccountException When the account has no Owner membership
     *                                   for its current owner_id (data corruption).
     */
    public function transferOwnership(
        Account $account,
        Model $newOwner,
        string $previousOwnerNewRoleKey = SystemRole::ADMIN,
    ): void {
        if ($previousOwnerNewRoleKey === SystemRole::OWNER) {
            throw CannotAssignOwnerRoleException::forContext('transferOwnership');
        }

        $previousOwnerNewRole = $this->requireRole($previousOwnerNewRoleKey);
        $ownerRole = $this->requireRole(SystemRole::OWNER);

        // Refresh against current DB state — the caller may be holding a
        // model that was loaded before another transferOwnership ran.
        $account = $account->fresh();

        if ($newOwner->id === $account->owner_id) {
            throw SelfOwnershipTransferException::forAccount($account->public_id);
        }

        $previousOwnerMembership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $account->owner_id)
            ->first();

        if ($previousOwnerMembership === null) {
            throw OwnerlessAccountException::forAccount($account->public_id);
        }

        $newOwnerMembership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $newOwner->id)
            ->first();

        if ($newOwnerMembership === null) {
            throw NotAMemberException::forUserAndAccount(
                $this->userIdentifier($newOwner),
                $account->public_id,
            );
        }

        /** @var class-string<Model> $userClass */
        $userClass = config('jamesgifford.auth.models.user');
        /** @var Model $previousOwner */
        $previousOwner = $userClass::query()->findOrFail($account->owner_id);

        DB::transaction(function () use (
            $account,
            $previousOwner,
            $newOwner,
            $previousOwnerMembership,
            $newOwnerMembership,
            $previousOwnerNewRole,
            $ownerRole,
        ): void {
            $previousOwnerMembership->update(['account_role_id' => $previousOwnerNewRole->id]);
            $newOwnerMembership->update(['account_role_id' => $ownerRole->id]);
            $account->update(['owner_id' => $newOwner->id]);

            DB::afterCommit(function () use ($account, $previousOwner, $newOwner, $previousOwnerNewRole): void {
                AccountOwnershipTransferred::dispatch(
                    AccountTransfer::fromModel($account),
                    UserTransfer::fromModel($previousOwner),
                    UserTransfer::fromModel($newOwner),
                    AccountRoleTransfer::fromModel($previousOwnerNewRole),
                );
            });
        });
    }

    /**
     * Soft-delete an account. Membership rows are preserved so the account
     * remains restorable.
     *
     * Side effects:
     *  - Sets accounts.deleted_at.
     *  - Nulls current_account_id on every user pointing at this account
     *    (bulk update, no per-user model events).
     *  - Dispatches {@see AccountDeleted} after commit.
     */
    public function delete(Account $account): void
    {
        $transfer = AccountTransfer::fromModel($account);

        /** @var class-string<Model> $userClass */
        $userClass = config('jamesgifford.auth.models.user');

        DB::transaction(function () use ($account, $userClass, $transfer): void {
            $account->delete();

            $userClass::query()
                ->where('current_account_id', $account->id)
                ->update(['current_account_id' => null]);

            DB::afterCommit(function () use ($transfer): void {
                AccountDeleted::dispatch($transfer);
            });
        });
    }

    /**
     * Restore a soft-deleted account.
     *
     * No-op (and no event) when the account is not currently soft-deleted —
     * matches Laravel's own SoftDeletes::restore() semantics and lets
     * callers express intent ("ensure restored") rather than starting state.
     *
     * Does NOT re-point current_account_id for previous holders: restoring
     * an account doesn't recover the user's prior preference.
     *
     * Side effects:
     *  - Clears accounts.deleted_at.
     *  - Dispatches {@see AccountRestored} after commit (only on real restore).
     */
    public function restore(Account $account): void
    {
        if (! $account->trashed()) {
            return;
        }

        DB::transaction(function () use ($account): void {
            $account->restore();

            DB::afterCommit(function () use ($account): void {
                AccountRestored::dispatch(AccountTransfer::fromModel($account));
            });
        });
    }

    /**
     * Permanently delete an account. Cannot be undone.
     *
     * Side effects:
     *  - Removes the accounts row.
     *  - FK cascadeOnDelete removes all account_user rows for this account.
     *  - FK nullOnDelete clears current_account_id on users that pointed here.
     *  - Dispatches {@see AccountForceDeleted} (with a pre-delete snapshot)
     *    after commit.
     *
     * AccountUser model events do NOT fire because the cleanup runs at the
     * FK layer, not through Eloquent.
     */
    public function forceDelete(Account $account): void
    {
        // Snapshot before delete; after forceDelete the model is gone and
        // fromModel() would read stale or null attributes.
        $transfer = AccountTransfer::fromModel($account);

        DB::transaction(function () use ($account, $transfer): void {
            $account->forceDelete();

            DB::afterCommit(function () use ($transfer): void {
                AccountForceDeleted::dispatch($transfer);
            });
        });
    }

    private function renderDefaultName(Model $owner): string
    {
        $template = config('jamesgifford.auth.accounts.default_name_template', "{name}'s Account");

        return str_replace('{name}', $owner->name ?? 'User', $template);
    }

    /**
     * Resolve a role by key, throwing if it isn't present in the database.
     * Pre-checks against RolesConfig so config-level missing keys produce
     * the same exception as DB-level missing rows (consistent failure mode
     * regardless of whether the seeder has run).
     */
    private function requireRole(string $key): AccountRole
    {
        if (! $this->rolesConfig->hasRole($key)) {
            throw InvalidRoleException::forKey($key);
        }

        $role = AccountRole::findByKey($key);

        if ($role === null) {
            throw InvalidRoleException::forKey($key);
        }

        return $role;
    }

    /**
     * The identifier used for this user in exception messages. Prefer
     * public_id; fall back to the integer primary key when the consumer's
     * User model does not use HasPublicId.
     */
    private function userIdentifier(Model $user): string
    {
        return $user->public_id ?? (string) $user->id;
    }
}
