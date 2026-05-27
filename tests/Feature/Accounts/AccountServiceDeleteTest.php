<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Facades\Event;
use Progravity\Auth\Accounts\Services\AccountService;
use Progravity\Auth\Events\AccountDeleted;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\AccountTransfer;

class AccountServiceDeleteTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_soft_deletes_the_account(): void
    {
        ['account' => $account] = $this->createUserWithAccount();

        $this->service->delete($account);

        $this->assertNotNull($account->fresh()->deleted_at);
        $this->assertNotNull(Account::withTrashed()->find($account->id));
    }

    public function test_account_query_without_with_trashed_excludes_deleted(): void
    {
        ['account' => $account] = $this->createUserWithAccount();

        $this->service->delete($account);

        $this->assertNull(Account::find($account->id));
    }

    public function test_account_deleted_event_is_dispatched(): void
    {
        Event::fake([AccountDeleted::class]);

        ['account' => $account] = $this->createUserWithAccount();

        $this->service->delete($account);

        Event::assertDispatched(AccountDeleted::class, 1);
    }

    public function test_event_carries_correct_account_transfer(): void
    {
        Event::fake([AccountDeleted::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $expectedId = $account->id;
        $expectedPublicId = $account->public_id;
        $expectedName = $account->name;

        $this->service->delete($account);

        Event::assertDispatched(AccountDeleted::class, function (AccountDeleted $event) use ($expectedId, $expectedPublicId, $expectedName) {
            return $event->account instanceof AccountTransfer
                && $event->account->id === $expectedId
                && $event->account->publicId === $expectedPublicId
                && $event->account->name === $expectedName;
        });
    }

    public function test_memberships_still_exist_after_soft_delete(): void
    {
        ['account' => $account, 'membership' => $ownerMembership] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $memberRowId = AccountUser::factory()->for($account)->for($member)->memberRole()->create()->id;

        $this->service->delete($account);

        $this->assertDatabaseHas('account_user', ['id' => $ownerMembership->id]);
        $this->assertDatabaseHas('account_user', ['id' => $memberRowId]);
    }

    public function test_current_account_id_nulled_for_pointing_users(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();
        $member->switchToAccount($account);

        $this->service->delete($account);

        $this->assertNull($member->fresh()->current_account_id);
    }

    public function test_current_account_id_not_affected_for_users_on_different_account(): void
    {
        // Two accounts. User A is on account 1; user B is on account 2.
        // We delete account 1 and check that user B's current_account_id is
        // untouched.
        ['user' => $ownerA, 'account' => $accountA] = $this->createUserWithAccount();
        ['user' => $ownerB, 'account' => $accountB] = $this->createUserWithAccount();

        $member = User::factory()->create();
        AccountUser::factory()->for($accountB)->for($member)->memberRole()->create();
        $member->switchToAccount($accountB);

        $this->service->delete($accountA);

        $this->assertSame($accountB->id, $member->fresh()->current_account_id);
    }

    public function test_cleanup_and_event_atomic_with_transaction(): void
    {
        Event::fake([AccountDeleted::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();
        $member->switchToAccount($account);

        // Force rollback after soft delete by hooking the Account 'deleted'
        // model event.
        Account::deleted(function (): void {
            throw new \RuntimeException('Forced rollback after delete');
        });

        try {
            $this->service->delete($account);
        } catch (\RuntimeException) {
            // expected
        }

        // Soft delete rolled back.
        $this->assertNull($account->fresh()->deleted_at);
        // current_account_id cleanup rolled back as well.
        $this->assertSame($account->id, $member->fresh()->current_account_id);
        // No event.
        Event::assertNotDispatched(AccountDeleted::class);
    }
}
