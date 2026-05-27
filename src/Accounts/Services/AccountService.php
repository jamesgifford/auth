<?php

declare(strict_types=1);

namespace Progravity\Auth\Accounts\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Progravity\Auth\Events\AccountCreated;
use Progravity\Auth\Events\AccountRoleChanged;
use Progravity\Auth\Events\UserAttachedToAccount;
use Progravity\Auth\Events\UserDetachedFromAccount;
use Progravity\Auth\Exceptions\AlreadyAMemberException;
use Progravity\Auth\Exceptions\CannotAssignOwnerRoleException;
use Progravity\Auth\Exceptions\CannotDetachOwnerException;
use Progravity\Auth\Exceptions\CannotModifyOwnerRoleException;
use Progravity\Auth\Exceptions\InvalidRoleException;
use Progravity\Auth\Exceptions\NotAMemberException;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountRole;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Roles\RolesConfig;
use Progravity\Auth\SystemRole;
use Progravity\Auth\Transfers\AccountRoleTransfer;
use Progravity\Auth\Transfers\AccountTransfer;
use Progravity\Auth\Transfers\MembershipTransfer;
use Progravity\Auth\Transfers\UserTransfer;

/**
 * Service layer for account and membership operations.
 *
 * All mutating methods wrap their database work in a transaction and queue
 * event dispatch via DB::afterCommit() so listeners never fire on a rolled
 * back transaction. Events carry readonly transfers rather than live model
 * references — see {@see \Progravity\Auth\Transfers}.
 *
 * Methods take the consumer's User model as an Illuminate Model because the
 * concrete class is configured via config('progravity.auth.models.user').
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
     *                             config('progravity.auth.accounts.default_name_template')
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
        $accountClass = config('progravity.auth.models.account');

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

    private function renderDefaultName(Model $owner): string
    {
        $template = config('progravity.auth.accounts.default_name_template', "{name}'s Account");

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
