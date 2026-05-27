<?php

declare(strict_types=1);

namespace Progravity\Auth\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Progravity\Auth\Exceptions\NotAMemberException;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountRole;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\SystemRole;

/**
 * Apply this trait to the consumer's User model to turn it into an account
 * member with relationships and role-checking helpers.
 *
 * The trait is a pure data/query layer; it does not enforce invariants such
 * as "every user must belong to an account." That is the application's
 * concern (registration flow, middleware). The single mutating method here
 * is {@see switchToAccount()}, which updates the user's current_account_id
 * after verifying membership.
 *
 * Composes independently with {@see \Progravity\Auth\PublicId\Concerns\HasPublicId}:
 *
 *     use HasPublicId, HasAccounts;
 */
trait HasAccounts
{
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(
            config('progravity.auth.models.account'),
            'account_user'
        )
            ->using(AccountUser::class)
            ->withPivot(['account_role_id', 'joined_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AccountUser::class, 'user_id');
    }

    public function currentAccount(): BelongsTo
    {
        return $this->belongsTo(
            config('progravity.auth.models.account'),
            'current_account_id'
        );
    }

    public function ownedAccounts(): HasMany
    {
        return $this->hasMany(
            config('progravity.auth.models.account'),
            'owner_id'
        );
    }

    public function belongsToAccount(Account $account): bool
    {
        return $this->memberships()
            ->where('account_id', $account->id)
            ->exists();
    }

    public function membershipIn(Account $account): ?AccountUser
    {
        return $this->memberships()
            ->where('account_id', $account->id)
            ->first();
    }

    public function roleIn(Account $account): ?AccountRole
    {
        return $this->membershipIn($account)?->role;
    }

    public function hasRole(Account $account, string $roleKey): bool
    {
        return $this->roleIn($account)?->key === $roleKey;
    }

    /**
     * @param  array<int, string>  $roleKeys
     */
    public function hasAnyRole(Account $account, array $roleKeys): bool
    {
        $role = $this->roleIn($account);

        return $role !== null && in_array($role->key, $roleKeys, true);
    }

    public function isOwnerOf(Account $account): bool
    {
        return $this->hasRole($account, SystemRole::OWNER);
    }

    public function isAdminOf(Account $account): bool
    {
        return $this->hasAnyRole($account, [SystemRole::OWNER, SystemRole::ADMIN]);
    }

    public function hasAnyAccount(): bool
    {
        return $this->memberships()->exists();
    }

    public function isFloating(): bool
    {
        return ! $this->hasAnyAccount();
    }

    /**
     * Set the user's current account and persist. Throws if the user is not
     * a member of the target account; without that guard, callers could leave
     * the user pointing at an inaccessible account.
     */
    public function switchToAccount(Account $account): void
    {
        if (! $this->belongsToAccount($account)) {
            throw NotAMemberException::forUserAndAccount(
                $this->public_id,
                $account->public_id
            );
        }

        $this->current_account_id = $account->id;
        $this->save();
    }

    public function scopeFloating(Builder $query): Builder
    {
        return $query->whereDoesntHave('memberships');
    }

    public function scopeWithAccount(Builder $query, Account $account): Builder
    {
        return $query->whereHas('memberships', function (Builder $q) use ($account): void {
            $q->where('account_id', $account->id);
        });
    }
}
