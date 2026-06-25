<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Facades\Event;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Events\AccountRoleChanged;
use JamesGifford\Auth\Exceptions\CannotAssignOwnerRoleException;
use JamesGifford\Auth\Exceptions\CannotModifyOwnerRoleException;
use JamesGifford\Auth\Exceptions\InvalidRoleException;
use JamesGifford\Auth\Exceptions\NotAMemberException;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Transfers\AccountRoleTransfer;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public static function roleTransitionProvider(): array
    {
        return [
            'admin to member' => ['admin', 'member'],
            'member to viewer' => ['member', 'viewer'],
            'viewer to admin' => ['viewer', 'admin'],
        ];
    }

    #[DataProvider('roleTransitionProvider')]
    public function test_role_transition_changes_membership_role(string $startRole, string $newRole): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->{$startRole.'Role'}()->create();

        $updated = $this->service->changeRole($account, $member, $newRole);

        $this->assertSame($newRole, $updated->role->key);
    }
}
