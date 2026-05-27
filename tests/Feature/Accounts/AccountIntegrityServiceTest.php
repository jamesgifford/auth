<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Facades\DB;
use Progravity\Auth\Accounts\Services\AccountIntegrityService;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountRole;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\IntegrityIssueTransfer;
use Progravity\Auth\Transfers\IntegrityIssueType;

class AccountIntegrityServiceTest extends AccountsTestCase
{
    private AccountIntegrityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountIntegrityService::class);
    }

    // ----- Healthy state -----

    public function test_scan_returns_empty_on_clean_database(): void
    {
        $this->createUserWithAccount();

        $issues = $this->service->scan();

        $this->assertCount(0, $issues);
    }

    public function test_scan_returns_empty_with_many_healthy_accounts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->createUserWithAccount();
        }

        $issues = $this->service->scan();

        $this->assertCount(0, $issues);
    }

    public function test_has_issues_false_on_clean_database(): void
    {
        $this->createUserWithAccount();

        $this->assertFalse($this->service->hasIssues());
    }

    public function test_scan_account_on_healthy_account_returns_empty(): void
    {
        ['account' => $account] = $this->createUserWithAccount();

        $issues = $this->service->scanAccount($account);

        $this->assertCount(0, $issues);
    }

    // ----- NoOwnerMembership detection -----

    public function test_detects_no_owner_membership(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();

        // Wipe the owner's membership row, leaving the account with no
        // Owner-role member. The account.owner_id still points at $owner
        // because owner_id is restrictOnDelete on the user side, but the
        // membership row deletion is allowed.
        AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $owner->id)
            ->delete();

        $issues = $this->service->scan();

        $this->assertCount(1, $issues);
        $issue = $issues->first();
        $this->assertInstanceOf(IntegrityIssueTransfer::class, $issue);
        $this->assertSame(IntegrityIssueType::NoOwnerMembership, $issue->type);
        $this->assertSame($account->id, $issue->accountId);
        $this->assertSame($account->public_id, $issue->accountPublicId);
        $this->assertSame($account->name, $issue->accountName);
        $this->assertSame($owner->id, $issue->metadata['owner_id']);
    }

    // ----- MultipleOwnerMemberships detection -----

    public function test_detects_multiple_owner_memberships(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();
        $second = User::factory()->create();

        // Bypass the service (which forbids this) and create a second
        // Owner-role membership directly.
        AccountUser::create([
            'account_id' => $account->id,
            'user_id' => $second->id,
            'account_role_id' => AccountRole::findByKey('owner')->id,
            'joined_at' => now(),
        ]);

        $issues = $this->service->scan();

        $this->assertCount(1, $issues);
        $issue = $issues->first();
        $this->assertSame(IntegrityIssueType::MultipleOwnerMemberships, $issue->type);
        $this->assertSame($account->id, $issue->accountId);
        $this->assertEqualsCanonicalizing(
            [$owner->id, $second->id],
            $issue->metadata['owner_membership_user_ids'],
        );
        $this->assertSame($owner->id, $issue->metadata['accounts_owner_id']);
    }

    // ----- OwnerIdMismatch detection -----

    public function test_detects_owner_id_mismatch(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();
        $other = User::factory()->create();
        AccountUser::factory()->for($account)->for($other)->adminRole()->create();

        // Re-point accounts.owner_id at $other without changing memberships.
        // The Owner-role membership still belongs to $owner; the FK is happy
        // because $other exists.
        DB::table('accounts')->where('id', $account->id)->update(['owner_id' => $other->id]);

        $issues = $this->service->scan();

        $this->assertCount(1, $issues);
        $issue = $issues->first();
        $this->assertSame(IntegrityIssueType::OwnerIdMismatch, $issue->type);
        $this->assertSame($account->id, $issue->accountId);
        $this->assertSame($other->id, $issue->metadata['accounts_owner_id']);
        $this->assertSame($owner->id, $issue->metadata['owner_membership_user_id']);
    }

    // ----- Multiple issues across accounts -----

    public function test_multiple_accounts_with_different_issues(): void
    {
        // Account A: missing owner membership.
        ['user' => $ownerA, 'account' => $accountA] = $this->createUserWithAccount();
        AccountUser::query()
            ->where('account_id', $accountA->id)
            ->where('user_id', $ownerA->id)
            ->delete();

        // Account B: mismatch.
        ['user' => $ownerB, 'account' => $accountB] = $this->createUserWithAccount();
        $otherB = User::factory()->create();
        AccountUser::factory()->for($accountB)->for($otherB)->memberRole()->create();
        DB::table('accounts')->where('id', $accountB->id)->update(['owner_id' => $otherB->id]);

        // Account C: healthy.
        $this->createUserWithAccount();

        $issues = $this->service->scan();

        $this->assertCount(2, $issues);

        $byAccount = $issues->keyBy('accountId');
        $this->assertSame(IntegrityIssueType::NoOwnerMembership, $byAccount->get($accountA->id)->type);
        $this->assertSame(IntegrityIssueType::OwnerIdMismatch, $byAccount->get($accountB->id)->type);
    }

    // ----- Soft-deleted exclusion -----

    public function test_soft_deleted_account_excluded_from_scan(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();

        // Break the invariant AND soft-delete.
        AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $owner->id)
            ->delete();
        $account->delete();

        $issues = $this->service->scan();

        // Soft-deleted accounts are skipped even when broken.
        $this->assertCount(0, $issues);
    }

    // ----- has_issues -----

    public function test_has_issues_true_when_corrupt(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();
        AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $owner->id)
            ->delete();

        $this->assertTrue($this->service->hasIssues());
    }

    // ----- scanAccount() -----

    public function test_scan_account_reports_issue_for_corrupt_account(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();
        AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $owner->id)
            ->delete();

        $issues = $this->service->scanAccount($account);

        $this->assertCount(1, $issues);
        $this->assertSame(IntegrityIssueType::NoOwnerMembership, $issues->first()->type);
    }

    public function test_scan_account_returns_empty_for_soft_deleted_account(): void
    {
        // scanAccount uses the same base query as scan(), so soft-deleted
        // accounts are excluded here too — verify the behavior is consistent.
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();
        AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $owner->id)
            ->delete();
        $account->delete();

        $issues = $this->service->scanAccount($account);

        $this->assertCount(0, $issues);
    }

    // ----- Performance smoke -----

    public function test_scan_completes_quickly_for_100_healthy_accounts(): void
    {
        // Create 100 owners and accounts. Disable model events on AccountUser
        // for setup speed; we're testing scan, not factory throughput.
        for ($i = 0; $i < 100; $i++) {
            $this->createUserWithAccount();
        }

        $start = microtime(true);
        $issues = $this->service->scan();
        $elapsed = microtime(true) - $start;

        $this->assertCount(0, $issues);
        // Generous bound — this is a sanity check, not a benchmark.
        $this->assertLessThan(2.0, $elapsed, "scan() took {$elapsed}s for 100 healthy accounts.");
    }
}
