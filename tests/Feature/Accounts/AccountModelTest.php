<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Database\QueryException;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Support\Fixtures\User;

class AccountModelTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_account_can_be_created_with_name_and_owner_id(): void
    {
        $user = User::factory()->create();

        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'name' => 'Acme',
            'owner_id' => $user->id,
        ]);
    }

    public function test_account_auto_generates_public_id_with_acc_prefix(): void
    {
        $user = User::factory()->create();

        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $this->assertNotNull($account->public_id);
        $this->assertStringStartsWith('acc_', $account->public_id);
    }

    public function test_owner_returns_the_user(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $this->assertInstanceOf(User::class, $account->owner);
        $this->assertSame($user->id, $account->owner->id);
    }

    public function test_members_is_empty_before_any_account_user_records_exist(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $this->assertCount(0, $account->members);
    }

    public function test_members_includes_owner_after_pivot_row_created(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        AccountUser::factory()->for($account)->for($user)->ownerRole()->create();

        $account->refresh();

        $this->assertCount(1, $account->members);
        $this->assertSame($user->id, $account->members->first()->id);
    }

    public function test_memberships_returns_account_user_records(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        AccountUser::factory()->for($account)->for($user)->ownerRole()->create();

        $this->assertCount(1, $account->memberships);
        $this->assertInstanceOf(AccountUser::class, $account->memberships->first());
    }

    public function test_owner_membership_returns_owner_row_when_exists(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $membership = AccountUser::factory()->for($account)->for($user)->ownerRole()->create();

        $found = $account->ownerMembership();

        $this->assertInstanceOf(AccountUser::class, $found);
        $this->assertSame($membership->id, $found->id);
    }

    public function test_owner_membership_returns_null_when_no_row(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $this->assertNull($account->ownerMembership());
    }

    public function test_soft_delete_keeps_row_recoverable(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $id = $account->id;

        $account->delete();

        $this->assertNotNull($account->fresh()->deleted_at);
        $this->assertNull(Account::find($id));
        $this->assertNotNull(Account::withTrashed()->find($id));
    }

    public function test_owner_fk_restricts_user_deletion(): void
    {
        $user = User::factory()->create();
        Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $this->expectException(QueryException::class);

        $user->delete();
    }

    public function test_name_and_owner_id_are_mass_assignable_but_public_id_is_not(): void
    {
        $user = User::factory()->create();

        $account = Account::create([
            'name' => 'Acme',
            'owner_id' => $user->id,
            'public_id' => 'acc_should_be_ignored',
        ]);

        $this->assertSame('Acme', $account->name);
        $this->assertSame($user->id, $account->owner_id);
        $this->assertNotSame('acc_should_be_ignored', $account->public_id);
        $this->assertStringStartsWith('acc_', $account->public_id);
    }
}
