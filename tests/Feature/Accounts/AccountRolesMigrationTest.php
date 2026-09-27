<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\SystemRole;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

/**
 * The account roles the package depends on ship as a migration, so any freshly
 * migrated database — migrate:fresh, a new test database under RefreshDatabase,
 * a first production deploy — has them without a seeder ever running. None of
 * these tests seed: the rows come from the migration alone.
 */
class AccountRolesMigrationTest extends AccountsTestCase
{
    public function test_a_freshly_migrated_database_has_every_configured_role(): void
    {
        $this->assertSame(
            array_keys(config('jamesgifford.auth.roles')),
            AccountRole::query()->orderBy('sort_order')->pluck('key')->all(),
        );

        $owner = AccountRole::findByKey(SystemRole::OWNER);
        $this->assertSame('Owner', $owner->name);
        $this->assertSame(
            'Full control over the account, including deletion and ownership transfer.',
            $owner->description,
        );
        $this->assertTrue($owner->system);
        $this->assertSame(1, $owner->sort_order);
        $this->assertNotNull($owner->created_at);
    }

    public function test_a_freshly_migrated_database_can_register_a_user_with_a_personal_account(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);

        event(new Registered($user));

        $account = Account::query()->where('owner_id', $user->id)->sole();
        $this->assertSame("Ada's Account", $account->name);
        $this->assertTrue($user->fresh()->isOwnerOf($account));
        $this->assertSame($account->id, $user->fresh()->current_account_id);
    }

    public function test_running_it_again_inserts_only_the_missing_roles(): void
    {
        AccountRole::query()->where('key', SystemRole::VIEWER)->delete(); // non-system guard does not apply to query deletes
        AccountRole::query()->where('key', SystemRole::OWNER)->update(['name' => 'Chief']);

        $this->rolesMigration()->up();

        $this->assertSame(4, AccountRole::query()->count());
        $this->assertNotNull(AccountRole::findByKey(SystemRole::VIEWER));
        // Existing rows are never rewritten: an app's own naming survives.
        $this->assertSame('Chief', AccountRole::findByKey(SystemRole::OWNER)->name);
    }

    public function test_it_inserts_roles_an_app_adds_to_the_roles_config(): void
    {
        config()->set('jamesgifford.auth.roles.auditor', [
            'name' => 'Auditor',
            'description' => 'Reviews account activity.',
            'system' => false,
            'sort_order' => 10,
        ]);

        $this->rolesMigration()->up();

        $auditor = AccountRole::findByKey('auditor');
        $this->assertNotNull($auditor);
        $this->assertFalse($auditor->system);
        $this->assertSame(10, $auditor->sort_order);
    }

    public function test_a_stale_empty_roles_config_falls_back_to_the_config_file(): void
    {
        // An app whose config was cached before the package was installed
        // skips mergeConfigFrom, leaving the roles key empty at runtime.
        DB::table('account_roles')->delete();
        config()->set('jamesgifford.auth.roles', []);

        $this->rolesMigration()->up();

        $this->assertSame(4, AccountRole::query()->count());
        $this->assertNotNull(AccountRole::findByKey(SystemRole::OWNER));
    }

    public function test_down_removes_the_roles(): void
    {
        $this->rolesMigration()->down();

        $this->assertSame(0, AccountRole::query()->count());
    }

    public function test_down_keeps_roles_that_memberships_still_reference(): void
    {
        $this->createUserWithAccount(role: SystemRole::OWNER);

        $this->rolesMigration()->down();

        // account_role_id is restrictOnDelete: a role in use cannot go, and
        // must not abort the rollback either.
        $this->assertSame([SystemRole::OWNER], AccountRole::query()->pluck('key')->all());
    }

    public function test_down_is_a_no_op_once_the_roles_table_is_gone(): void
    {
        Schema::drop('account_user');
        Schema::drop('account_roles');

        $this->rolesMigration()->down();

        $this->assertFalse(Schema::hasTable('account_roles'));
    }

    private function rolesMigration(): Migration
    {
        $files = glob(__DIR__.'/../../../database/migrations/*_insert_jamesgifford_auth_account_roles.php') ?: [];
        $this->assertCount(1, $files, 'The package should ship exactly one account roles migration.');

        return require $files[0];
    }
}
