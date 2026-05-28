<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Accounts\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\SystemRole;
use JamesGifford\Auth\Transfers\IntegrityIssueTransfer;
use JamesGifford\Auth\Transfers\IntegrityIssueType;

/**
 * Read-only scanner for Owner-invariant violations across accounts.
 *
 * Deliberately separated from {@see AccountService}: that service mutates
 * one account at a time and emits events; this one queries many accounts
 * read-only and emits nothing. Auto-repair is intentionally out of scope —
 * once an account is corrupt, the right fix is usually case-by-case (decide
 * which membership wins, which user becomes owner) and not safely automatable.
 *
 * Excludes soft-deleted accounts: they're not actively in use, so their
 * Owner invariant matters less and including them would clutter the report.
 */
final class AccountIntegrityService
{
    /**
     * Scan every non-deleted account and return all detected issues.
     *
     * @return Collection<int, IntegrityIssueTransfer>
     */
    public function scan(): Collection
    {
        $ownerRole = AccountRole::findByKey(SystemRole::OWNER);

        if ($ownerRole === null) {
            // The Owner role row is missing — package setup is broken at a
            // level beyond what this service can usefully report on. Caller
            // bug or seeder not run; returning empty avoids producing
            // misleading per-account issues.
            return collect();
        }

        $issues = collect();

        $this->accountQuery($ownerRole)
            ->chunk(500, function (EloquentCollection $accounts) use ($issues, $ownerRole): void {
                foreach ($accounts as $account) {
                    foreach ($this->checkAccount($account, $ownerRole) as $issue) {
                        $issues->push($issue);
                    }
                }
            });

        return $issues;
    }

    /**
     * Scan a single account (typically a known-corrupt one) and return its
     * issues. Useful for tests and post-incident investigation.
     *
     * @return Collection<int, IntegrityIssueTransfer>
     */
    public function scanAccount(Account $account): Collection
    {
        $ownerRole = AccountRole::findByKey(SystemRole::OWNER);

        if ($ownerRole === null) {
            return collect();
        }

        $account = $this->accountQuery($ownerRole)
            ->whereKey($account->id)
            ->first();

        if ($account === null) {
            return collect();
        }

        return collect($this->checkAccount($account, $ownerRole));
    }

    /**
     * Whether scan() would surface at least one issue. Currently runs the
     * full scan and inspects the result; a streaming early-exit version
     * could replace this if scan times become a problem at very large scale.
     */
    public function hasIssues(): bool
    {
        return $this->scan()->isNotEmpty();
    }

    /**
     * @return Builder<Account>
     */
    private function accountQuery(AccountRole $ownerRole): Builder
    {
        /** @var class-string<Account> $accountClass */
        $accountClass = config('jamesgifford.auth.models.account');

        return $accountClass::query()
            ->with(['memberships' => function ($query) use ($ownerRole): void {
                $query->where('account_role_id', $ownerRole->id);
            }]);
    }

    /**
     * Run all three checks against one account; return the resulting issues
     * (zero, one, or in pathological cases more than one).
     *
     * @return list<IntegrityIssueTransfer>
     */
    private function checkAccount(Account $account, AccountRole $ownerRole): array
    {
        /** @var EloquentCollection<int, AccountUser> $ownerMemberships */
        $ownerMemberships = $account->memberships;
        $issues = [];

        if ($ownerMemberships->isEmpty()) {
            $issues[] = new IntegrityIssueTransfer(
                accountId: $account->id,
                accountPublicId: $account->public_id,
                accountName: $account->name,
                type: IntegrityIssueType::NoOwnerMembership,
                description: 'Account has no member with the Owner role.',
                metadata: ['owner_id' => $account->owner_id],
            );

            return $issues;
        }

        if ($ownerMemberships->count() > 1) {
            $issues[] = new IntegrityIssueTransfer(
                accountId: $account->id,
                accountPublicId: $account->public_id,
                accountName: $account->name,
                type: IntegrityIssueType::MultipleOwnerMemberships,
                description: "Account has {$ownerMemberships->count()} members with the Owner role; must have exactly one.",
                metadata: [
                    'owner_membership_user_ids' => $ownerMemberships->pluck('user_id')->all(),
                    'accounts_owner_id' => $account->owner_id,
                ],
            );

            return $issues;
        }

        $ownerMembership = $ownerMemberships->first();

        if ($ownerMembership->user_id !== $account->owner_id) {
            $issues[] = new IntegrityIssueTransfer(
                accountId: $account->id,
                accountPublicId: $account->public_id,
                accountName: $account->name,
                type: IntegrityIssueType::OwnerIdMismatch,
                description: "Account's owner_id ({$account->owner_id}) does not match the user with Owner role ({$ownerMembership->user_id}).",
                metadata: [
                    'accounts_owner_id' => $account->owner_id,
                    'owner_membership_user_id' => $ownerMembership->user_id,
                ],
            );
        }

        return $issues;
    }
}
