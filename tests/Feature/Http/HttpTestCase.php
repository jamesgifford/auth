<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Tests\TestCase;

/**
 * Base test case for the package's HTTP layer. Loads Laravel's tables plus the
 * package migrations, points the user model at the fixture, enables sqlite
 * foreign keys, and seeds roles so AccountService::create() works.
 */
abstract class HttpTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::clearBootedModels();
        $this->seed(AccountRoleSeeder::class);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('jamesgifford.auth.models.user', User::class);

        $connection = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.foreign_key_constraints", true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    /**
     * A user with an account they own, made current.
     *
     * @return array{user: User, account: Account}
     */
    protected function userWithAccount(?string $name = null): array
    {
        $user = User::factory()->create($name === null ? [] : ['name' => $name]);
        $account = app(AccountService::class)->create($user);
        $user->switchToAccount($account);

        return ['user' => $user->fresh(), 'account' => $account];
    }

    protected function makeAccountFor(User $user): Account
    {
        return app(AccountService::class)->create($user);
    }
}
