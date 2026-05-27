<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Facades\Event;
use Progravity\Auth\Accounts\Services\AccountService;
use Progravity\Auth\Events\AccountForceDeleted;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\AccountTransfer;

class AccountServiceForceDeleteTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_hard_deletes_the_account_row(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $id = $account->id;

        $this->service->forceDelete($account);

        $this->assertNull(Account::find($id));
        $this->assertNull(Account::withTrashed()->find($id));
    }

    public function test_event_dispatched_with_pre_delete_snapshot(): void
    {
        Event::fake([AccountForceDeleted::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $expectedId = $account->id;
        $expectedPublicId = $account->public_id;
        $expectedName = $account->name;
        $expectedOwnerId = $account->owner_id;

        $this->service->forceDelete($account);

        Event::assertDispatched(AccountForceDeleted::class, function (AccountForceDeleted $event) use (
            $expectedId, $expectedPublicId, $expectedName, $expectedOwnerId,
        ) {
            return $event->account instanceof AccountTransfer
                && $event->account->id === $expectedId
                && $event->account->publicId === $expectedPublicId
                && $event->account->name === $expectedName
                && $event->account->ownerId === $expectedOwnerId;
        });
    }

    public function test_account_user_rows_cascade_deleted(): void
    {
        ['account' => $account, 'membership' => $ownerMembership] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $memberMembershipId = AccountUser::factory()->for($account)->for($member)->memberRole()->create()->id;

        $this->service->forceDelete($account);

        $this->assertDatabaseMissing('account_user', ['id' => $ownerMembership->id]);
        $this->assertDatabaseMissing('account_user', ['id' => $memberMembershipId]);
    }

    public function test_current_account_id_nulled_by_fk(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();
        $member->switchToAccount($account);

        $this->service->forceDelete($account);

        $this->assertNull($member->fresh()->current_account_id);
    }

    public function test_cannot_be_undone(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $id = $account->id;

        $this->service->forceDelete($account);

        $this->assertNull(Account::find($id));
        $this->assertNull(Account::withTrashed()->find($id));
    }
}
