<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Progravity\Auth\Models\Account;
use Progravity\Auth\Models\AccountRole;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\Tests\Support\Fixtures\User;

class FactoryTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_account_factory_creates_account_with_owner_id(): void
    {
        $user = User::factory()->create();

        $account = Account::factory()->create(['owner_id' => $user->id]);

        $this->assertInstanceOf(Account::class, $account);
        $this->assertSame($user->id, $account->owner_id);
        $this->assertNotEmpty($account->name);
    }

    public function test_account_factory_owned_by_sets_owner_id(): void
    {
        $user = User::factory()->create();

        $account = Account::factory()->ownedBy($user)->create();

        $this->assertSame($user->id, $account->owner_id);
    }

    public function test_account_role_factory_creates_custom_role_by_default(): void
    {
        $role = AccountRole::factory()->create();

        $this->assertFalse($role->system);
    }

    public function test_account_role_factory_system_state(): void
    {
        $role = AccountRole::factory()->system()->create();

        $this->assertTrue($role->system);
    }

    public function test_account_user_factory_owner_role_resolves_after_seeding(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->ownedBy($user)->create();

        $membership = AccountUser::factory()->for($account)->for($user)->ownerRole()->create();

        $this->assertSame(AccountRole::findByKey('owner')->id, $membership->account_role_id);
    }

    public function test_account_user_factory_with_role_resolves_any_seeded_role(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->ownedBy($user)->create();

        $membership = AccountUser::factory()->for($account)->for($user)->withRole('viewer')->create();

        $this->assertSame(AccountRole::findByKey('viewer')->id, $membership->account_role_id);
    }
}
