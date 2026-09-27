<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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

        // The account routes run in the web group, whose cookie encryption
        // needs an application key — every real app has one; Testbench doesn't.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        $connection = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.foreign_key_constraints", true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

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

    /**
     * Laravel's CSRF middleware waves every request through while unit tests
     * run (runningUnitTests()), which would let a CSRF assertion pass whether
     * or not the route is protected. Swap in a subclass that never takes that
     * shortcut, so the token check genuinely runs for the rest of the test.
     */
    protected function enforceCsrfProtection(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }
}
