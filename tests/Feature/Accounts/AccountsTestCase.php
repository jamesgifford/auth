<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Database\Eloquent\Model;
use Progravity\Auth\Database\Seeders\AccountRoleSeeder;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Tests\TestCase;

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
        $app['config']->set('progravity.auth.models.user', User::class);

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
     * Seed the account_roles table from config('progravity.auth.roles').
     */
    protected function seedRoles(): void
    {
        $this->seed(AccountRoleSeeder::class);
    }
}
