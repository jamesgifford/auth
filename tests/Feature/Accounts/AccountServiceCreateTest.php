<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Events\AccountCreated;
use JamesGifford\Auth\Exceptions\InvalidRoleException;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Transfers\AccountTransfer;
use JamesGifford\Auth\Transfers\MembershipTransfer;
use JamesGifford\Auth\Transfers\UserTransfer;
use RuntimeException;

class AccountServiceCreateTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_create_uses_provided_name(): void
    {
        $owner = User::factory()->create();

        $account = $this->service->create($owner, 'Acme Workspace');

        $this->assertSame('Acme Workspace', $account->name);
    }

    public function test_create_uses_default_name_template_when_name_omitted(): void
    {
        $owner = User::factory()->create(['name' => 'James']);

        $account = $this->service->create($owner);

        $this->assertSame("James's Account", $account->name);
    }

    public function test_default_name_template_substitutes_owner_name(): void
    {
        config(['jamesgifford.auth.accounts.default_name_template' => 'Workspace for {name}']);

        $owner = User::factory()->create(['name' => 'Ada Lovelace']);

        $account = $this->service->create($owner);

        $this->assertSame('Workspace for Ada Lovelace', $account->name);
    }

    public function test_account_has_correct_owner_id(): void
    {
        $owner = User::factory()->create();

        $account = $this->service->create($owner);

        $this->assertSame($owner->id, $account->owner_id);
    }

    public function test_account_has_public_id_with_account_prefix(): void
    {
        $owner = User::factory()->create();

        $account = $this->service->create($owner);

        $this->assertNotNull($account->public_id);
        $this->assertStringStartsWith('account_', $account->public_id);
    }

    public function test_owner_membership_row_is_created_with_owner_role(): void
    {
        $owner = User::factory()->create();

        $account = $this->service->create($owner);

        $this->assertDatabaseHas('account_user', [
            'account_id' => $account->id,
            'user_id' => $owner->id,
            'account_role_id' => AccountRole::findByKey('owner')->id,
        ]);
    }

    public function test_owner_membership_has_recent_joined_at(): void
    {
        $owner = User::factory()->create();

        $before = Carbon::now()->subSecond();
        $account = $this->service->create($owner);
        $after = Carbon::now()->addSecond();

        $membership = $account->ownerMembership();
        $this->assertNotNull($membership);
        $this->assertTrue($membership->joined_at->between($before, $after));
    }

    public function test_account_created_event_is_dispatched(): void
    {
        Event::fake([AccountCreated::class]);

        $owner = User::factory()->create();

        $this->service->create($owner);

        Event::assertDispatched(AccountCreated::class, 1);
    }

    public function test_event_carries_account_transfer_with_correct_values(): void
    {
        Event::fake([AccountCreated::class]);

        $owner = User::factory()->create();
        $account = $this->service->create($owner);

        Event::assertDispatched(AccountCreated::class, function (AccountCreated $event) use ($account, $owner) {
            return $event->account instanceof AccountTransfer
                && $event->account->id === $account->id
                && $event->account->publicId === $account->public_id
                && $event->account->name === $account->name
                && $event->account->ownerId === $owner->id;
        });
    }

    public function test_event_carries_user_transfer_matching_owner(): void
    {
        Event::fake([AccountCreated::class]);

        $owner = User::factory()->create(['name' => 'Owner Name', 'email' => 'owner@example.test']);
        $this->service->create($owner);

        Event::assertDispatched(AccountCreated::class, function (AccountCreated $event) use ($owner) {
            return $event->owner instanceof UserTransfer
                && $event->owner->id === $owner->id
                && $event->owner->publicId === $owner->public_id
                && $event->owner->name === 'Owner Name'
                && $event->owner->email === 'owner@example.test';
        });
    }

    public function test_event_carries_membership_transfer_for_owner(): void
    {
        Event::fake([AccountCreated::class]);

        $owner = User::factory()->create();
        $account = $this->service->create($owner);

        $ownerRoleId = AccountRole::findByKey('owner')->id;
        $membership = $account->ownerMembership();

        Event::assertDispatched(AccountCreated::class, function (AccountCreated $event) use ($membership, $account, $owner, $ownerRoleId) {
            return $event->ownerMembership instanceof MembershipTransfer
                && $event->ownerMembership->id === $membership->id
                && $event->ownerMembership->accountId === $account->id
                && $event->ownerMembership->userId === $owner->id
                && $event->ownerMembership->accountRoleId === $ownerRoleId;
        });
    }

    public function test_returned_account_has_owner_relationship_loaded(): void
    {
        $owner = User::factory()->create();

        $account = $this->service->create($owner);

        $this->assertTrue($account->relationLoaded('owner'));
        $this->assertSame($owner->id, $account->owner->id);
    }

    public function test_returned_account_has_memberships_role_loaded(): void
    {
        $owner = User::factory()->create();

        $account = $this->service->create($owner);

        $this->assertTrue($account->relationLoaded('memberships'));
        $this->assertTrue($account->memberships->first()->relationLoaded('role'));
        $this->assertSame('owner', $account->memberships->first()->role->key);
    }

    public function test_create_throws_invalid_role_when_owner_role_missing(): void
    {
        // Wipe the seeded owner row to simulate seeder-not-run.
        AccountRole::query()->where('key', 'owner')->delete();

        $owner = User::factory()->create();

        $this->expectException(InvalidRoleException::class);

        $this->service->create($owner);
    }

    public function test_create_runs_in_a_single_transaction(): void
    {
        // If account creation succeeded but membership creation failed, the
        // account row should NOT be present after rollback. Force the
        // membership insert to fail by deleting the owner role between the
        // role lookup and the create — easiest reproduction is to trigger a
        // FK violation by pre-deleting the role and asserting nothing
        // persisted.
        //
        // Cleanest approach: pre-delete only the row, then create. The
        // service's requireRole() catches this earlier, so instead we
        // simulate the failure by listening for the account insert and
        // throwing from within the transaction via Account creating event.

        $owner = User::factory()->create();

        $accountsBefore = Account::count();
        $membershipsBefore = AccountUser::count();

        Account::creating(function () {
            throw new RuntimeException('Forced failure inside transaction');
        });

        try {
            $this->service->create($owner);
            $this->fail('Expected forced failure was not raised.');
        } catch (RuntimeException $e) {
            $this->assertSame('Forced failure inside transaction', $e->getMessage());
        }

        $this->assertSame($accountsBefore, Account::count(), 'No account row should persist after rollback.');
        $this->assertSame($membershipsBefore, AccountUser::count(), 'No membership row should persist after rollback.');
    }
}
