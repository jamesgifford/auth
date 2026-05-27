<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Progravity\Auth\Accounts\Services\AccountService;
use Progravity\Auth\Events\AccountDeleted;
use Progravity\Auth\Events\AccountForceDeleted;
use Progravity\Auth\Events\AccountOwnershipTransferred;
use Progravity\Auth\Events\AccountRestored;
use Progravity\Auth\Exceptions\SelfOwnershipTransferException;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Feature\Accounts\AccountsTestCase;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\AccountTransfer;

class OwnershipAndLifecycleEventsTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_ownership_transferred_event_fires_with_correct_transfers(): void
    {
        Event::fake([AccountOwnershipTransferred::class]);

        ['user' => $previousOwner, 'account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $this->service->transferOwnership($account, $newOwner);

        Event::assertDispatched(AccountOwnershipTransferred::class, function (AccountOwnershipTransferred $event) use ($account, $previousOwner, $newOwner) {
            return $event->account instanceof AccountTransfer
                && $event->account->id === $account->id
                && $event->previousOwner->id === $previousOwner->id
                && $event->newOwner->id === $newOwner->id
                && $event->previousOwnerNewRole->key === 'admin';
        });
    }

    public function test_account_deleted_event_fires_with_correct_transfer(): void
    {
        Event::fake([AccountDeleted::class]);

        ['account' => $account] = $this->createUserWithAccount();

        $this->service->delete($account);

        Event::assertDispatched(AccountDeleted::class, function (AccountDeleted $event) use ($account) {
            return $event->account->id === $account->id;
        });
    }

    public function test_account_restored_event_fires_with_correct_transfer(): void
    {
        Event::fake([AccountRestored::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $this->service->delete($account);

        $this->service->restore($account);

        Event::assertDispatched(AccountRestored::class, function (AccountRestored $event) use ($account) {
            return $event->account->id === $account->id;
        });
    }

    public function test_account_force_deleted_event_fires_with_pre_delete_snapshot(): void
    {
        Event::fake([AccountForceDeleted::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $expectedPublicId = $account->public_id;
        $expectedName = $account->name;

        $this->service->forceDelete($account);

        Event::assertDispatched(AccountForceDeleted::class, function (AccountForceDeleted $event) use ($account, $expectedPublicId, $expectedName) {
            return $event->account->id === $account->id
                && $event->account->publicId === $expectedPublicId
                && $event->account->name === $expectedName;
        });
    }

    public function test_no_ownership_event_when_operation_throws(): void
    {
        Event::fake([AccountOwnershipTransferred::class]);

        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();

        try {
            $this->service->transferOwnership($account, $owner);
        } catch (SelfOwnershipTransferException) {
            // expected
        }

        Event::assertNotDispatched(AccountOwnershipTransferred::class);
    }

    public function test_events_defer_to_outer_transaction_commit(): void
    {
        Event::fake([AccountDeleted::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $accountId = $account->id;

        try {
            DB::transaction(function () use ($account): void {
                $this->service->delete($account);

                // Force outer rollback. The service's afterCommit hook is
                // scheduled against the outer transaction, so the event must
                // not fire.
                throw new \RuntimeException('Outer rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Outer rollback', $e->getMessage());
        }

        $this->assertNull(Account::find($accountId)?->deleted_at);
        $this->assertNotNull(Account::find($accountId), 'Account row should still exist after outer rollback.');
        Event::assertNotDispatched(AccountDeleted::class);
    }
}
