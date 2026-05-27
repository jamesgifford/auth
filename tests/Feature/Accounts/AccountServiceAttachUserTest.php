<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Progravity\Auth\Accounts\Services\AccountService;
use Progravity\Auth\Events\UserAttachedToAccount;
use Progravity\Auth\Exceptions\AlreadyAMemberException;
use Progravity\Auth\Exceptions\CannotAssignOwnerRoleException;
use Progravity\Auth\Exceptions\InvalidRoleException;
use Progravity\Auth\Models\AccountRole;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\AccountRoleTransfer;
use Progravity\Auth\Transfers\AccountTransfer;
use Progravity\Auth\Transfers\MembershipTransfer;
use Progravity\Auth\Transfers\UserTransfer;

class AccountServiceAttachUserTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_attach_user_creates_membership_with_specified_role(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        $membership = $this->service->attachUser($account, $newcomer, 'admin');

        $this->assertDatabaseHas('account_user', [
            'id' => $membership->id,
            'account_id' => $account->id,
            'user_id' => $newcomer->id,
            'account_role_id' => AccountRole::findByKey('admin')->id,
        ]);
    }

    public function test_attach_user_sets_joined_at_to_now(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        $before = Carbon::now()->subSecond();
        $membership = $this->service->attachUser($account, $newcomer, 'member');
        $after = Carbon::now()->addSecond();

        $this->assertTrue($membership->joined_at->between($before, $after));
    }

    public function test_attach_user_returns_membership_with_role_loaded(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        $membership = $this->service->attachUser($account, $newcomer, 'viewer');

        $this->assertTrue($membership->relationLoaded('role'));
        $this->assertSame('viewer', $membership->role->key);
    }

    public function test_user_attached_event_is_dispatched(): void
    {
        Event::fake([UserAttachedToAccount::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        $this->service->attachUser($account, $newcomer, 'member');

        Event::assertDispatched(UserAttachedToAccount::class, 1);
    }

    public function test_event_carries_correct_transfers(): void
    {
        Event::fake([UserAttachedToAccount::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create(['name' => 'Newcomer', 'email' => 'new@example.test']);

        $membership = $this->service->attachUser($account, $newcomer, 'admin');

        Event::assertDispatched(UserAttachedToAccount::class, function (UserAttachedToAccount $event) use ($account, $newcomer, $membership) {
            return $event->account instanceof AccountTransfer
                && $event->account->id === $account->id
                && $event->user instanceof UserTransfer
                && $event->user->id === $newcomer->id
                && $event->user->name === 'Newcomer'
                && $event->user->email === 'new@example.test'
                && $event->role instanceof AccountRoleTransfer
                && $event->role->key === 'admin'
                && $event->membership instanceof MembershipTransfer
                && $event->membership->id === $membership->id;
        });
    }

    public function test_throws_invalid_role_when_key_does_not_exist(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        $this->expectException(InvalidRoleException::class);
        $this->expectExceptionMessage("'auditor'");

        $this->service->attachUser($account, $newcomer, 'auditor');
    }

    public function test_throws_cannot_assign_owner_when_role_is_owner(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        $this->expectException(CannotAssignOwnerRoleException::class);
        $this->expectExceptionMessage('attachUser');

        $this->service->attachUser($account, $newcomer, 'owner');
    }

    public function test_throws_already_a_member_when_user_already_has_membership(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $this->expectException(AlreadyAMemberException::class);
        $this->expectExceptionMessage($user->public_id);
        $this->expectExceptionMessage($account->public_id);

        $this->service->attachUser($account, $user, 'member');
    }

    public function test_already_a_member_message_steers_toward_change_role(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        try {
            $this->service->attachUser($account, $user, 'member');
            $this->fail('Expected AlreadyAMemberException was not thrown.');
        } catch (AlreadyAMemberException $e) {
            $this->assertStringContainsString('changeRole', $e->getMessage());
        }
    }

    public function test_no_membership_created_after_invalid_role_exception(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        $countBefore = AccountUser::count();

        try {
            $this->service->attachUser($account, $newcomer, 'nonexistent');
        } catch (InvalidRoleException) {
            // expected
        }

        $this->assertSame($countBefore, AccountUser::count());
    }

    public function test_no_event_dispatched_after_invalid_role_exception(): void
    {
        Event::fake([UserAttachedToAccount::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        try {
            $this->service->attachUser($account, $newcomer, 'nonexistent');
        } catch (InvalidRoleException) {
            // expected
        }

        Event::assertNotDispatched(UserAttachedToAccount::class);
    }

    public function test_no_membership_created_after_owner_role_rejected(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        $countBefore = AccountUser::count();

        try {
            $this->service->attachUser($account, $newcomer, 'owner');
        } catch (CannotAssignOwnerRoleException) {
            // expected
        }

        $this->assertSame($countBefore, AccountUser::count());
    }

    public function test_no_event_dispatched_after_owner_role_rejected(): void
    {
        Event::fake([UserAttachedToAccount::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $newcomer = User::factory()->create();

        try {
            $this->service->attachUser($account, $newcomer, 'owner');
        } catch (CannotAssignOwnerRoleException) {
            // expected
        }

        Event::assertNotDispatched(UserAttachedToAccount::class);
    }

    public function test_works_for_users_without_public_id_in_exception_messages(): void
    {
        // Simulate a user model that lacks public_id by clearing the
        // attribute. The exception should still produce a useful identifier.
        ['account' => $account] = $this->createUserWithAccount();

        $newcomer = User::factory()->create();
        AccountUser::factory()->for($account)->for($newcomer)->memberRole()->create();

        // Force public_id absent on the in-memory model.
        $newcomer->setAttribute('public_id', null);

        try {
            $this->service->attachUser($account, $newcomer, 'admin');
            $this->fail('Expected AlreadyAMemberException was not thrown.');
        } catch (AlreadyAMemberException $e) {
            $this->assertStringContainsString((string) $newcomer->id, $e->getMessage());
            $this->assertStringContainsString($account->public_id, $e->getMessage());
        }
    }
}
