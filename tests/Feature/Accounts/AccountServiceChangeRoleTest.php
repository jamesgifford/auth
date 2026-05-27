<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Facades\Event;
use Progravity\Auth\Accounts\Services\AccountService;
use Progravity\Auth\Events\AccountRoleChanged;
use Progravity\Auth\Exceptions\CannotAssignOwnerRoleException;
use Progravity\Auth\Exceptions\CannotModifyOwnerRoleException;
use Progravity\Auth\Exceptions\InvalidRoleException;
use Progravity\Auth\Exceptions\NotAMemberException;
use Progravity\Auth\Models\AccountRole;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\AccountRoleTransfer;

class AccountServiceChangeRoleTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_change_role_updates_membership_role(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $membership = AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $this->service->changeRole($account, $member, 'admin');

        $this->assertSame(AccountRole::findByKey('admin')->id, $membership->fresh()->account_role_id);
    }

    public function test_returned_membership_has_new_role_loaded(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $updated = $this->service->changeRole($account, $member, 'viewer');

        $this->assertTrue($updated->relationLoaded('role'));
        $this->assertSame('viewer', $updated->role->key);
    }

    public function test_role_changed_event_is_dispatched(): void
    {
        Event::fake([AccountRoleChanged::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $this->service->changeRole($account, $member, 'admin');

        Event::assertDispatched(AccountRoleChanged::class, 1);
    }

    public function test_event_carries_previous_and_new_role_transfers(): void
    {
        Event::fake([AccountRoleChanged::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $this->service->changeRole($account, $member, 'admin');

        Event::assertDispatched(AccountRoleChanged::class, function (AccountRoleChanged $event) use ($account, $member) {
            return $event->account->id === $account->id
                && $event->user->id === $member->id
                && $event->previousRole instanceof AccountRoleTransfer
                && $event->previousRole->key === 'member'
                && $event->newRole instanceof AccountRoleTransfer
                && $event->newRole->key === 'admin';
        });
    }

    public function test_no_op_when_new_role_equals_current_role(): void
    {
        Event::fake([AccountRoleChanged::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $membership = AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $updatedAtBefore = $membership->updated_at;

        // Sleep is not necessary; the no-op path should not update at all.
        $returned = $this->service->changeRole($account, $member, 'member');

        // No event.
        Event::assertNotDispatched(AccountRoleChanged::class);

        // No DB update — updated_at unchanged.
        $this->assertEquals($updatedAtBefore->format('Y-m-d H:i:s'), $membership->fresh()->updated_at->format('Y-m-d H:i:s'));

        // Returned membership still matches.
        $this->assertSame($membership->id, $returned->id);
    }

    public function test_throws_invalid_role_when_new_role_key_does_not_exist(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $this->expectException(InvalidRoleException::class);

        $this->service->changeRole($account, $member, 'nonexistent');
    }

    public function test_throws_cannot_assign_owner_when_new_role_is_owner(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $this->expectException(CannotAssignOwnerRoleException::class);
        $this->expectExceptionMessage('changeRole');

        $this->service->changeRole($account, $member, 'owner');
    }

    public function test_throws_not_a_member_when_user_has_no_membership(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->expectException(NotAMemberException::class);

        $this->service->changeRole($account, $stranger, 'admin');
    }

    public function test_throws_cannot_modify_owner_role_when_user_is_owner(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();

        $this->expectException(CannotModifyOwnerRoleException::class);
        $this->expectExceptionMessage($owner->public_id);
        $this->expectExceptionMessage($account->public_id);

        $this->service->changeRole($account, $owner, 'admin');
    }

    public function test_role_unchanged_after_exception(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $membership = AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $originalRoleId = $membership->account_role_id;

        try {
            $this->service->changeRole($account, $member, 'nonexistent');
        } catch (InvalidRoleException) {
            // expected
        }

        $this->assertSame($originalRoleId, $membership->fresh()->account_role_id);
    }

    public function test_no_event_dispatched_after_exception(): void
    {
        Event::fake([AccountRoleChanged::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        try {
            $this->service->changeRole($account, $member, 'owner');
        } catch (CannotAssignOwnerRoleException) {
            // expected
        }

        Event::assertNotDispatched(AccountRoleChanged::class);
    }

    public function test_admin_to_member_change(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->adminRole()->create();

        $updated = $this->service->changeRole($account, $member, 'member');

        $this->assertSame('member', $updated->role->key);
    }

    public function test_member_to_viewer_change(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $updated = $this->service->changeRole($account, $member, 'viewer');

        $this->assertSame('viewer', $updated->role->key);
    }

    public function test_viewer_to_admin_change(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->viewerRole()->create();

        $updated = $this->service->changeRole($account, $member, 'admin');

        $this->assertSame('admin', $updated->role->key);
    }
}
