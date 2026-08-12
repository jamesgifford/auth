<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Database;

use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Tests\Support\Fixtures\QuietDatabaseSeeder;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

/**
 * End-to-end reproduction of the originally reported bug: a consumer's real
 * DatabaseSeeder — WithoutModelEvents, roles then dev data, exactly the shape
 * DatabaseSeederWiring writes — must seed users AND accounts with a populated
 * public_id even though model events (and therefore the trait's `creating`
 * listener) never fire.
 *
 * Before the HasPublicId fix (Task 2 of this plan), this failed with a NOT
 * NULL constraint violation on public_id, because generation lived solely in
 * a `creating` event listener that WithoutModelEvents suppresses.
 */
class DevDataSeederWithoutEventsTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // QuietDatabaseSeeder itself calls AccountRoleSeeder first, matching
        // the real consumer wiring — no separate seedRoles() call here.
        config(['jamesgifford.auth-dev' => [
            'environments' => ['local', 'testing'],
            'users_password' => 'dev-secret-pw',
            'accounts' => [
                ['name' => 'Dev Workspace', 'owner' => 'owner@example.test'],
            ],
            'users' => [
                ['name' => 'Dev Owner', 'email' => 'owner@example.test'],
                ['name' => 'Dev Member', 'email' => 'member@example.test', 'memberships' => [
                    ['account' => 'Dev Workspace', 'role' => 'admin'],
                ]],
            ],
        ]]);
    }

    public function test_users_and_accounts_get_public_ids_when_seeded_without_model_events(): void
    {
        // NOT run() and NOT $this->app->make(...)->run(): __invoke() is what
        // applies WithoutModelEvents (Seeder.php:187), and setContainer() is
        // what lets $this->call(...) inside run() container-resolve its
        // targets (Seeder::resolve(), Seeder.php:126) instead of fataling on
        // DevDataSeeder's constructor dependencies.
        $seeder = $this->app->make(QuietDatabaseSeeder::class);
        $seeder->setContainer($this->app);
        $seeder->__invoke();

        $users = User::query()->get();
        $this->assertSame(2, $users->count(), 'both declared dev users were seeded');

        foreach ($users as $user) {
            $this->assertNotEmpty($user->public_id, "user {$user->email} has no public_id");
            $this->assertTrue(PublicId::isValid((string) $user->public_id), "invalid public_id: {$user->public_id}");
        }

        $accounts = Account::query()->get();
        $this->assertSame(1, $accounts->count(), 'the declared dev account was seeded');

        foreach ($accounts as $account) {
            $this->assertNotEmpty($account->public_id, "account {$account->name} has no public_id");
            $this->assertTrue(PublicId::isValid((string) $account->public_id), "invalid public_id: {$account->public_id}");
        }
    }
}
