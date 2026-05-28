<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Models\AccountRole;

class AccountRoleSeederTest extends AccountsTestCase
{
    public function test_seeder_creates_all_four_system_roles(): void
    {
        $this->seed(AccountRoleSeeder::class);

        $this->assertSame(4, AccountRole::query()->count());
        foreach (['owner', 'admin', 'member', 'viewer'] as $key) {
            $this->assertNotNull(AccountRole::findByKey($key));
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AccountRoleSeeder::class);
        $this->seed(AccountRoleSeeder::class);

        $this->assertSame(4, AccountRole::query()->count());
    }

    public function test_seeded_roles_have_correct_attributes(): void
    {
        $this->seed(AccountRoleSeeder::class);

        $owner = AccountRole::findByKey('owner');
        $this->assertSame('Owner', $owner->name);
        $this->assertSame(
            'Full control over the account, including deletion and ownership transfer.',
            $owner->description
        );
        $this->assertTrue($owner->system);
        $this->assertSame(1, $owner->sort_order);

        $viewer = AccountRole::findByKey('viewer');
        $this->assertSame('Viewer', $viewer->name);
        $this->assertTrue($viewer->system);
        $this->assertSame(4, $viewer->sort_order);
    }

    public function test_consumer_added_roles_are_seeded_as_non_system(): void
    {
        config()->set('jamesgifford.auth.roles.auditor', [
            'name' => 'Auditor',
            'description' => 'Reviews account activity.',
            'system' => false,
            'sort_order' => 10,
        ]);

        $this->seed(AccountRoleSeeder::class);

        $auditor = AccountRole::findByKey('auditor');
        $this->assertNotNull($auditor);
        $this->assertFalse($auditor->system);
        $this->assertSame('Auditor', $auditor->name);
    }

    public function test_reseeding_updates_a_renamed_system_role_in_place(): void
    {
        $this->seed(AccountRoleSeeder::class);

        config()->set('jamesgifford.auth.roles.owner.name', 'Chief');
        $this->seed(AccountRoleSeeder::class);

        $owner = AccountRole::findByKey('owner');
        $this->assertSame('Chief', $owner->name);
        $this->assertSame('owner', $owner->key);
        $this->assertSame(4, AccountRole::query()->count());
    }

    public function test_removing_a_role_from_config_does_not_delete_the_orphan(): void
    {
        $this->seed(AccountRoleSeeder::class);

        $roles = config('jamesgifford.auth.roles');
        unset($roles['viewer']);
        config()->set('jamesgifford.auth.roles', $roles);

        $this->seed(AccountRoleSeeder::class);

        $this->assertNotNull(AccountRole::findByKey('viewer'));
        $this->assertSame(4, AccountRole::query()->count());
    }
}
