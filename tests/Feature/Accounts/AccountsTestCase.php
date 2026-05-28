<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Tests\TestCase;

/**
 * Base test case for the accounts subsystem.
 *
 * Provides the database (Laravel's tables plus the package migrations that
 * ALTER users and create the accounts tables), points the package's user
 * model at the test fixture, enables sqlite foreign keys so the schema's
 * cascade/restrict/null-on-delete behaviour is exercised, and exposes a
 * seedRoles() helper.
 */
abstract class AccountsTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The HasPublicId trait registers prefixes on first boot; clearing
        // booted models ensures each test re-boots against its own container.
        Model::clearBootedModels();
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Point the package's user model at the test fixture; the consumer's
        // App\Models\User does not exist in the test environment.
        $app['config']->set('jamesgifford.auth.models.user', User::class);

        // Testbench's in-memory sqlite connection ships with foreign keys
        // disabled; enable them so the cascade/restrict/null-on-delete
        // behaviour declared in the migrations is actually exercised.
        $connection = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.foreign_key_constraints", true);
    }

    /**
     * Load Laravel's default tables (users, etc.) followed by the package's
     * own migrations, which ALTER users and create the accounts tables.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    /**
     * Seed the account_roles table from config('jamesgifford.auth.roles').
     */
    protected function seedRoles(): void
    {
        $this->seed(AccountRoleSeeder::class);
    }

    /**
     * Create a user, an account they own, and an AccountUser membership row.
     * Saves boilerplate in trait/service tests where a fully-formed account
     * member is the starting point. Requires seedRoles() to have run.
     *
     * @return array{user: User, account: Account, membership: AccountUser}
     */
    protected function createUserWithAccount(
        ?string $name = null,
        string $role = 'owner',
    ): array {
        $user = User::factory()->create(['name' => $name ?? fake()->name()]);

        $account = Account::factory()->ownedBy($user)->create();

        $membership = AccountUser::factory()
            ->for($account)
            ->for($user)
            ->state(['account_role_id' => AccountRole::findByKey($role)->id])
            ->create();

        return ['user' => $user, 'account' => $account, 'membership' => $membership];
    }
}
