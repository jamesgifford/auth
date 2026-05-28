<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class AccountUserModelTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_account_user_can_be_created(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $membership = AccountUser::factory()->for($account)->for($user)->ownerRole()->create();

        $this->assertDatabaseHas('account_user', [
            'id' => $membership->id,
            'account_id' => $account->id,
            'user_id' => $user->id,
            'account_role_id' => AccountRole::findByKey('owner')->id,
        ]);
        $this->assertInstanceOf(Carbon::class, $membership->joined_at);
    }

    public function test_relationships_resolve(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $membership = AccountUser::factory()->for($account)->for($user)->ownerRole()->create();

        $this->assertInstanceOf(Account::class, $membership->account);
        $this->assertSame($account->id, $membership->account->id);
        $this->assertInstanceOf(User::class, $membership->user);
        $this->assertSame($user->id, $membership->user->id);
        $this->assertInstanceOf(AccountRole::class, $membership->role);
        $this->assertSame('owner', $membership->role->key);
    }

    public function test_is_owner_is_true_for_owner_role(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $membership = AccountUser::factory()->for($account)->for($user)->ownerRole()->create();

        $this->assertTrue($membership->isOwner());
    }

    public function test_is_owner_is_false_for_other_roles(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $membership = AccountUser::factory()->for($account)->for($user)->adminRole()->create();

        $this->assertFalse($membership->isOwner());
    }

    public function test_has_role_matches_any_role_key(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $membership = AccountUser::factory()->for($account)->for($user)->adminRole()->create();

        $this->assertTrue($membership->hasRole('admin'));
        $this->assertFalse($membership->hasRole('viewer'));
    }

    public function test_unique_constraint_blocks_duplicate_membership(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        AccountUser::factory()->for($account)->for($user)->ownerRole()->create();

        $this->expectException(QueryException::class);

        AccountUser::factory()->for($account)->for($user)->memberRole()->create();
    }

    public function test_deleting_account_cascades_membership(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $membership = AccountUser::factory()->for($account)->for($user)->ownerRole()->create();

        // Account uses soft deletes; force a hard delete to exercise the FK cascade.
        $account->forceDelete();

        $this->assertDatabaseMissing('account_user', ['id' => $membership->id]);
    }

    public function test_deleting_user_cascades_membership(): void
    {
        $owner = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $owner->id]);

        // A non-owner member so deleting the user isn't blocked by the owner FK.
        $member = User::factory()->create();
        $membership = AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $member->delete();

        $this->assertDatabaseMissing('account_user', ['id' => $membership->id]);
    }

    public function test_deleting_role_in_use_is_restricted(): void
    {
        $role = AccountRole::factory()->create();
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        AccountUser::factory()->for($account)->for($user)->withRole($role->key)->create();

        $this->expectException(QueryException::class);

        $role->delete();
    }
}
