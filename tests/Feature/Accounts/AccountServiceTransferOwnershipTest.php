<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Facades\Event;
use Progravity\Auth\Accounts\Services\AccountService;
use Progravity\Auth\Events\AccountOwnershipTransferred;
use Progravity\Auth\Exceptions\CannotAssignOwnerRoleException;
use Progravity\Auth\Exceptions\InvalidRoleException;
use Progravity\Auth\Exceptions\NotAMemberException;
use Progravity\Auth\Exceptions\OwnerlessAccountException;
use Progravity\Auth\Exceptions\SelfOwnershipTransferException;
use Progravity\Auth\Models\AccountRole;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\SystemRole;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\AccountRoleTransfer;
use Progravity\Auth\Transfers\AccountTransfer;
use Progravity\Auth\Transfers\UserTransfer;

class AccountServiceTransferOwnershipTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    // ----- Happy path -----

    public function test_transfers_ownership_to_existing_admin_member(): void
    {
        ['user' => $previousOwner, 'account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->adminRole()->create();

        $this->service->transferOwnership($account, $newOwner);

        $this->assertSame($newOwner->id, $account->fresh()->owner_id);
    }

    public function test_previous_owner_membership_demoted_to_admin_by_default(): void
    {
        ['user' => $previousOwner, 'account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $this->service->transferOwnership($account, $newOwner);

        $membership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $previousOwner->id)
            ->first();

        $this->assertSame(AccountRole::findByKey('admin')->id, $membership->account_role_id);
    }

    public function test_new_owner_membership_has_owner_role(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $this->service->transferOwnership($account, $newOwner);

        $membership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $newOwner->id)
            ->first();

        $this->assertSame(AccountRole::findByKey('owner')->id, $membership->account_role_id);
    }

    public function test_event_is_dispatched_with_correct_transfers(): void
    {
        Event::fake([AccountOwnershipTransferred::class]);

        ['user' => $previousOwner, 'account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $this->service->transferOwnership($account, $newOwner);

        Event::assertDispatched(AccountOwnershipTransferred::class, function (AccountOwnershipTransferred $event) use ($account, $previousOwner, $newOwner) {
            return $event->account instanceof AccountTransfer
                && $event->account->id === $account->id
                && $event->account->ownerId === $newOwner->id
                && $event->previousOwner instanceof UserTransfer
                && $event->previousOwner->id === $previousOwner->id
                && $event->newOwner instanceof UserTransfer
                && $event->newOwner->id === $newOwner->id
                && $event->previousOwnerNewRole instanceof AccountRoleTransfer
                && $event->previousOwnerNewRole->key === 'admin';
        });
    }

    public function test_returns_void(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $result = $this->service->transferOwnership($account, $newOwner);

        $this->assertNull($result);
    }

    // ----- Custom previous-owner role -----

    public function test_can_demote_previous_owner_to_member(): void
    {
        ['user' => $previousOwner, 'account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $this->service->transferOwnership($account, $newOwner, SystemRole::MEMBER);

        $membership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $previousOwner->id)
            ->first();

        $this->assertSame(AccountRole::findByKey('member')->id, $membership->account_role_id);
    }

    public function test_can_demote_previous_owner_to_viewer(): void
    {
        ['user' => $previousOwner, 'account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $this->service->transferOwnership($account, $newOwner, 'viewer');

        $membership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $previousOwner->id)
            ->first();

        $this->assertSame(AccountRole::findByKey('viewer')->id, $membership->account_role_id);
    }

    public function test_custom_consumer_role_works_for_demotion(): void
    {
        $custom = AccountRole::create([
            'key' => 'auditor',
            'name' => 'Auditor',
            'system' => false,
            'sort_order' => 99,
        ]);
        // Register the custom role with the rolesConfig at runtime by
        // updating config so the service's pre-check accepts it.
        config(['progravity.auth.roles.auditor' => [
            'name' => 'Auditor',
            'description' => 'Custom auditor role',
            'system' => false,
            'sort_order' => 99,
        ]]);

        // Rebuild the RolesConfig and the service against the updated config.
        $this->app->forgetInstance(\Progravity\Auth\Roles\RolesConfig::class);
        $this->app->forgetInstance(AccountService::class);
        $service = $this->app->make(AccountService::class);

        ['user' => $previousOwner, 'account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $service->transferOwnership($account, $newOwner, 'auditor');

        $membership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $previousOwner->id)
            ->first();

        $this->assertSame($custom->id, $membership->account_role_id);
    }

    // ----- Error cases -----

    public function test_throws_self_ownership_transfer_when_new_owner_is_current(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();

        $this->expectException(SelfOwnershipTransferException::class);
        $this->expectExceptionMessage($account->public_id);

        $this->service->transferOwnership($account, $owner);
    }

    public function test_throws_not_a_member_when_new_owner_is_not_a_member(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->expectException(NotAMemberException::class);
        $this->expectExceptionMessage($stranger->public_id);
        $this->expectExceptionMessage($account->public_id);

        $this->service->transferOwnership($account, $stranger);
    }

    public function test_throws_ownerless_account_when_owner_membership_is_missing(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        // Manually delete the owner's membership row to simulate corruption.
        AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $owner->id)
            ->delete();

        $this->expectException(OwnerlessAccountException::class);
        $this->expectExceptionMessage($account->public_id);

        $this->service->transferOwnership($account, $newOwner);
    }

    public function test_throws_cannot_assign_owner_when_previous_owner_role_is_owner(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $this->expectException(CannotAssignOwnerRoleException::class);
        $this->expectExceptionMessage('transferOwnership');

        $this->service->transferOwnership($account, $newOwner, SystemRole::OWNER);
    }

    public function test_throws_invalid_role_when_previous_owner_role_does_not_exist(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $this->expectException(InvalidRoleException::class);

        $this->service->transferOwnership($account, $newOwner, 'nonexistent');
    }

    public function test_no_state_changes_after_self_transfer_attempt(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();
        $originalOwnerId = $account->owner_id;
        $originalMembershipRoleId = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $owner->id)
            ->value('account_role_id');

        try {
            $this->service->transferOwnership($account, $owner);
        } catch (SelfOwnershipTransferException) {
            // expected
        }

        $this->assertSame($originalOwnerId, $account->fresh()->owner_id);
        $this->assertSame(
            $originalMembershipRoleId,
            AccountUser::query()
                ->where('account_id', $account->id)
                ->where('user_id', $owner->id)
                ->value('account_role_id'),
        );
    }

    public function test_no_event_after_failed_transfer(): void
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

    // ----- Atomicity -----

    public function test_atomicity_all_three_changes_revert_on_rollback(): void
    {
        Event::fake([AccountOwnershipTransferred::class]);

        ['user' => $previousOwner, 'account' => $account] = $this->createUserWithAccount();
        $newOwner = User::factory()->create();
        AccountUser::factory()->for($account)->for($newOwner)->memberRole()->create();

        $originalOwnerId = $account->owner_id;
        $originalPreviousOwnerRoleId = AccountRole::findByKey('owner')->id;
        $originalNewOwnerRoleId = AccountRole::findByKey('member')->id;

        // Force a rollback after all three updates have run inside the
        // transaction. Listening for the Account 'updated' model event gives
        // us a hook fired after $account->update() — which is the last of
        // the three writes — without interfering with the AccountUser updates.
        \Progravity\Auth\Models\Account::updated(function (): void {
            throw new \RuntimeException('Forced rollback after final update');
        });

        try {
            $this->service->transferOwnership($account, $newOwner);
            $this->fail('Expected forced rollback was not raised.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Forced rollback after final update', $e->getMessage());
        }

        // All three values revert.
        $this->assertSame($originalOwnerId, $account->fresh()->owner_id);
        $this->assertSame(
            $originalPreviousOwnerRoleId,
            AccountUser::query()
                ->where('account_id', $account->id)
                ->where('user_id', $previousOwner->id)
                ->value('account_role_id'),
        );
        $this->assertSame(
            $originalNewOwnerRoleId,
            AccountUser::query()
                ->where('account_id', $account->id)
                ->where('user_id', $newOwner->id)
                ->value('account_role_id'),
        );

        Event::assertNotDispatched(AccountOwnershipTransferred::class);
    }

    // ----- Edge cases -----

    public function test_stale_account_model_works_via_fresh_call(): void
    {
        // Load an account model BEFORE a transfer happens, then pass it to a
        // second transfer. The service's $account->fresh() call should pull
        // the current owner_id before deciding what to do.
        ['user' => $original, 'account' => $account] = $this->createUserWithAccount();
        $second = User::factory()->create();
        AccountUser::factory()->for($account)->for($second)->memberRole()->create();
        $third = User::factory()->create();
        AccountUser::factory()->for($account)->for($third)->memberRole()->create();

        // First transfer: original -> second.
        $this->service->transferOwnership($account, $second);

        // Caller still holds the original $account model (owner_id stale).
        // Second transfer should still work against current state: second -> third.
        $this->service->transferOwnership($account, $third);

        $this->assertSame($third->id, $account->fresh()->owner_id);
    }

    public function test_transfer_works_when_new_owner_is_viewer(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $viewer = User::factory()->create();
        AccountUser::factory()->for($account)->for($viewer)->viewerRole()->create();

        $this->service->transferOwnership($account, $viewer);

        $this->assertSame($viewer->id, $account->fresh()->owner_id);
        $membership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $viewer->id)
            ->first();
        $this->assertSame(AccountRole::findByKey('owner')->id, $membership->account_role_id);
    }
}
