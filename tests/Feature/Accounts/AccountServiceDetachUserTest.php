<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Facades\Event;
use Progravity\Auth\Accounts\Services\AccountService;
use Progravity\Auth\Events\UserDetachedFromAccount;
use Progravity\Auth\Exceptions\CannotDetachOwnerException;
use Progravity\Auth\Exceptions\NotAMemberException;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\AccountRoleTransfer;
use RuntimeException;

class AccountServiceDetachUserTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_detach_user_removes_membership_row(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $membership = AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $this->service->detachUser($account, $member);

        $this->assertDatabaseMissing('account_user', ['id' => $membership->id]);
    }

    public function test_user_detached_event_is_dispatched(): void
    {
        Event::fake([UserDetachedFromAccount::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $this->service->detachUser($account, $member);

        Event::assertDispatched(UserDetachedFromAccount::class, 1);
    }

    public function test_event_carries_previous_role_correctly(): void
    {
        Event::fake([UserDetachedFromAccount::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->adminRole()->create();

        $this->service->detachUser($account, $member);

        Event::assertDispatched(UserDetachedFromAccount::class, function (UserDetachedFromAccount $event) use ($account, $member) {
            return $event->account->id === $account->id
                && $event->user->id === $member->id
                && $event->previousRole instanceof AccountRoleTransfer
                && $event->previousRole->key === 'admin';
        });
    }

    public function test_throws_not_a_member_when_user_has_no_membership(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->expectException(NotAMemberException::class);
        $this->expectExceptionMessage($stranger->public_id);
        $this->expectExceptionMessage($account->public_id);

        $this->service->detachUser($account, $stranger);
    }

    public function test_throws_cannot_detach_owner_when_user_is_owner(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();

        $this->expectException(CannotDetachOwnerException::class);
        $this->expectExceptionMessage($owner->public_id);
        $this->expectExceptionMessage($account->public_id);

        $this->service->detachUser($account, $owner);
    }

    public function test_owner_membership_persists_after_failed_owner_detach(): void
    {
        ['user' => $owner, 'account' => $account, 'membership' => $membership] = $this->createUserWithAccount();

        try {
            $this->service->detachUser($account, $owner);
        } catch (CannotDetachOwnerException) {
            // expected
        }

        $this->assertDatabaseHas('account_user', ['id' => $membership->id]);
    }

    public function test_no_event_after_failed_owner_detach(): void
    {
        Event::fake([UserDetachedFromAccount::class]);

        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();

        try {
            $this->service->detachUser($account, $owner);
        } catch (CannotDetachOwnerException) {
            // expected
        }

        Event::assertNotDispatched(UserDetachedFromAccount::class);
    }

    public function test_cleans_up_current_account_id_when_pointing_at_detached_account(): void
    {
        ['account' => $account] = $this->createUserWithAccount();

        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();
        $member->switchToAccount($account);

        $this->assertSame($account->id, $member->fresh()->current_account_id);

        $this->service->detachUser($account, $member);

        $this->assertNull($member->fresh()->current_account_id);
    }

    public function test_does_not_clean_up_current_account_id_for_different_account(): void
    {
        ['user' => $primaryOwner, 'account' => $primaryAccount] = $this->createUserWithAccount();

        // Member belongs to a SECOND account; their current_account_id points
        // at that second account. Detaching from the FIRST account must not
        // touch the pointer.
        $secondAccount = Account::factory()->ownedBy($primaryOwner)->create();
        AccountUser::factory()->for($secondAccount)->for($primaryOwner)->ownerRole()->create();

        $member = User::factory()->create();
        AccountUser::factory()->for($primaryAccount)->for($member)->memberRole()->create();
        AccountUser::factory()->for($secondAccount)->for($member)->memberRole()->create();
        $member->switchToAccount($secondAccount);

        $this->service->detachUser($primaryAccount, $member);

        $this->assertSame($secondAccount->id, $member->fresh()->current_account_id);
    }

    public function test_current_account_id_cleanup_persists(): void
    {
        ['account' => $account] = $this->createUserWithAccount();

        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();
        $member->switchToAccount($account);

        $this->service->detachUser($account, $member);

        // Reload from DB rather than relying on in-memory mutation.
        $reloaded = User::find($member->id);
        $this->assertNull($reloaded->current_account_id);
    }

    public function test_event_fires_after_commit_not_during_transaction(): void
    {
        // If something inside the transaction throws after the delete but
        // before commit, the event must not fire. We can demonstrate this
        // by hooking into the AccountUser deleted event to throw, and
        // asserting the user-detached event never fires.
        Event::fake([UserDetachedFromAccount::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        AccountUser::deleted(function (): void {
            throw new RuntimeException('Forced failure after delete');
        });

        try {
            $this->service->detachUser($account, $member);
        } catch (RuntimeException) {
            // expected
        }

        Event::assertNotDispatched(UserDetachedFromAccount::class);
    }
}
