<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Progravity\Auth\Accounts\Services\AccountService;
use Progravity\Auth\Events\AccountCreated;
use Progravity\Auth\Events\AccountRoleChanged;
use Progravity\Auth\Events\UserAttachedToAccount;
use Progravity\Auth\Events\UserDetachedFromAccount;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Feature\Accounts\AccountsTestCase;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\AccountRoleTransfer;
use Progravity\Auth\Transfers\AccountTransfer;
use Progravity\Auth\Transfers\MembershipTransfer;
use Progravity\Auth\Transfers\UserTransfer;

class EventDispatchTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_all_four_events_dispatch_at_correct_times(): void
    {
        Event::fake([
            AccountCreated::class,
            UserAttachedToAccount::class,
            UserDetachedFromAccount::class,
            AccountRoleChanged::class,
        ]);

        $owner = User::factory()->create();

        // create() fires AccountCreated
        $account = $this->service->create($owner);
        Event::assertDispatched(AccountCreated::class, 1);
        Event::assertNotDispatched(UserAttachedToAccount::class);

        // attachUser() fires UserAttachedToAccount
        $member = User::factory()->create();
        $this->service->attachUser($account, $member, 'member');
        Event::assertDispatched(UserAttachedToAccount::class, 1);
        Event::assertNotDispatched(AccountRoleChanged::class);

        // changeRole() fires AccountRoleChanged
        $this->service->changeRole($account, $member, 'admin');
        Event::assertDispatched(AccountRoleChanged::class, 1);
        Event::assertNotDispatched(UserDetachedFromAccount::class);

        // detachUser() fires UserDetachedFromAccount
        $this->service->detachUser($account, $member);
        Event::assertDispatched(UserDetachedFromAccount::class, 1);
    }

    public function test_event_does_not_fire_when_outer_transaction_is_rolled_back(): void
    {
        Event::fake([AccountCreated::class]);

        $owner = User::factory()->create();
        $accountCountBefore = Account::count();

        try {
            DB::transaction(function () use ($owner): void {
                $this->service->create($owner);

                // Force the outer transaction to roll back. The service's
                // afterCommit hook is scheduled against this outer transaction
                // (since the service's DB::transaction is nested), so it
                // should NOT fire when this rollback runs.
                throw new \RuntimeException('Outer rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Outer rollback', $e->getMessage());
        }

        // The account row should not persist...
        $this->assertSame($accountCountBefore, Account::count());

        // ...and the event must not have fired.
        Event::assertNotDispatched(AccountCreated::class);
    }

    public function test_event_listeners_receive_transfers_not_live_models(): void
    {
        Event::fake([UserAttachedToAccount::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $this->service->attachUser($account, $member, 'admin');

        Event::assertDispatched(UserAttachedToAccount::class, function (UserAttachedToAccount $event) {
            // Property types confirm the payload is a transfer, not a model.
            return $event->account instanceof AccountTransfer
                && $event->user instanceof UserTransfer
                && $event->role instanceof AccountRoleTransfer
                && $event->membership instanceof MembershipTransfer
                && ! ($event->account instanceof Account)
                && ! ($event->membership instanceof AccountUser);
        });
    }
}
