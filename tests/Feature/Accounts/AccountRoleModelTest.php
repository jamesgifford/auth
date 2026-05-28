<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use Illuminate\Database\QueryException;
use JamesGifford\Auth\Exceptions\CannotDeleteSystemRoleException;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class AccountRoleModelTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_role_can_be_created_with_all_attributes(): void
    {
        $role = AccountRole::create([
            'key' => 'auditor',
            'name' => 'Auditor',
            'description' => 'Reviews account activity.',
            'system' => false,
            'sort_order' => 50,
        ]);

        $this->assertDatabaseHas('account_roles', [
            'key' => 'auditor',
            'name' => 'Auditor',
            'description' => 'Reviews account activity.',
            'system' => false,
            'sort_order' => 50,
        ]);
        $this->assertFalse($role->system);
        $this->assertSame(50, $role->sort_order);
    }

    public function test_find_by_key_returns_role_after_seeding(): void
    {
        $role = AccountRole::findByKey('owner');

        $this->assertInstanceOf(AccountRole::class, $role);
        $this->assertSame('owner', $role->key);
    }

    public function test_find_by_key_returns_null_for_unknown_key(): void
    {
        $this->assertNull(AccountRole::findByKey('nonexistent'));
    }

    public function test_is_system_reflects_system_flag(): void
    {
        $this->assertTrue(AccountRole::findByKey('owner')->isSystem());

        $custom = AccountRole::factory()->create();
        $this->assertFalse($custom->isSystem());
    }

    public function test_memberships_returns_account_user_records_using_this_role(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        $membership = AccountUser::factory()->for($account)->for($user)->viewerRole()->create();

        $viewer = AccountRole::findByKey('viewer');

        $this->assertCount(1, $viewer->memberships);
        $this->assertSame($membership->id, $viewer->memberships->first()->id);
    }

    public function test_deleting_a_system_role_throws(): void
    {
        $owner = AccountRole::findByKey('owner');

        $this->expectException(CannotDeleteSystemRoleException::class);

        $owner->delete();
    }

    public function test_deleting_a_custom_role_succeeds_when_unused(): void
    {
        $role = AccountRole::factory()->create();

        $role->delete();

        $this->assertDatabaseMissing('account_roles', ['id' => $role->id]);
    }

    public function test_deleting_a_custom_role_fails_at_db_level_when_in_use(): void
    {
        $role = AccountRole::factory()->create();
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);
        AccountUser::factory()->for($account)->for($user)->withRole($role->key)->create();

        $this->expectException(QueryException::class);

        $role->delete();
    }
}
