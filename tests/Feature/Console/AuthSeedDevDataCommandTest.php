<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use JamesGifford\Auth\Database\DevDataSeeder;
use JamesGifford\Auth\Exceptions\DevDataSeedingNotAllowedException;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class AuthSeedDevDataCommandTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->configureDevData();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            foreach (['dev-data.php', 'auth.php'] as $name) {
                @unlink(config_path('jamesgifford'.DIRECTORY_SEPARATOR.$name));
            }
        }
        parent::tearDown();
    }

    // ---- Publishing the dev-data config (deliberate, not default-on-install) ----

    public function test_dev_data_config_is_publishable_via_its_own_tag(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'dev-data.php');
        @unlink($target);

        Artisan::call('vendor:publish', ['--tag' => 'jamesgifford-auth-dev-data', '--force' => true]);

        $this->assertFileExists($target);
    }

    public function test_dev_data_config_is_not_published_by_the_default_config_tag(): void
    {
        @unlink(config_path('jamesgifford'.DIRECTORY_SEPARATOR.'dev-data.php'));

        Artisan::call('vendor:publish', ['--tag' => 'jamesgifford-auth-config', '--force' => true]);

        // The main config publish must NOT bring the dev-only file with it.
        $this->assertFileDoesNotExist(config_path('jamesgifford'.DIRECTORY_SEPARATOR.'dev-data.php'));
    }

    public function test_seeding_publishes_the_dev_data_config_on_first_run(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'dev-data.php');
        @unlink($target);

        $this->artisan('jamesgifford:auth:seed-dev-data')
            ->expectsOutputToContain('Published dev-data config')
            ->assertSuccessful();

        $this->assertFileExists($target);
    }

    public function test_seeding_does_not_overwrite_an_existing_dev_data_config(): void
    {
        $dir = config_path('jamesgifford');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $target = $dir.DIRECTORY_SEPARATOR.'dev-data.php';
        file_put_contents($target, "<?php\n\n// consumer dev marker keep-me-77\nreturn [];\n");

        Artisan::call('jamesgifford:auth:seed-dev-data');
        $output = Artisan::output();

        $this->assertStringContainsString('keep-me-77', (string) file_get_contents($target));
        $this->assertStringNotContainsString('Published dev-data config', $output);
    }

    public function test_a_refused_run_does_not_publish_the_dev_data_config(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'dev-data.php');
        @unlink($target);
        $this->app['env'] = 'production';

        $code = Artisan::call('jamesgifford:auth:seed-dev-data');

        $this->app['env'] = 'testing';

        // Refused = write nothing: neither the config file nor the database.
        $this->assertSame(1, $code);
        $this->assertFileDoesNotExist($target);
    }

    // ---- Environment guard (fails-closed allowlist + unconditional production) ----

    public function test_refuses_in_production_and_makes_no_database_changes(): void
    {
        $this->app['env'] = 'production';

        $code = Artisan::call('jamesgifford:auth:seed-dev-data');
        $output = Artisan::output();

        $this->app['env'] = 'testing'; // restore before teardown's migrate rollback

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refusing to seed dev data in a production environment', $output);
        $this->assertSame(0, User::query()->count(), 'A refused run must not touch the database.');
    }

    public function test_refuses_when_environment_is_not_in_the_allowlist(): void
    {
        $this->app['env'] = 'staging'; // not in ['local','testing']

        $code = Artisan::call('jamesgifford:auth:seed-dev-data');
        $output = Artisan::output();

        $this->app['env'] = 'testing';

        $this->assertSame(1, $code);
        $this->assertStringContainsString("environment 'staging' is not in the allowed list", $output);
        $this->assertSame(0, User::query()->count());
    }

    public function test_production_is_refused_even_if_the_allowlist_includes_it(): void
    {
        // Misconfigured allowlist must not override the unconditional refusal.
        $this->configureDevData(['environments' => ['production', 'local', 'testing']]);
        $this->app['env'] = 'production';

        $code = Artisan::call('jamesgifford:auth:seed-dev-data');
        $output = Artisan::output();

        $this->app['env'] = 'testing';

        $this->assertSame(1, $code);
        $this->assertStringContainsString('production environment', $output);
        $this->assertSame(0, User::query()->count());
    }

    public function test_guard_throws_before_any_database_access(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->app->make(DevDataSeeder::class)->seed();
            $this->fail('Expected the seeder to refuse in production.');
        } catch (DevDataSeedingNotAllowedException) {
            // Expected — and nothing should have been written.
            $this->assertSame(0, User::query()->count());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    // ---- Seeding (runs in the testing environment) ----

    public function test_seeds_users_with_a_hashed_shared_password(): void
    {
        $this->artisan('jamesgifford:auth:seed-dev-data')->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('dev-secret-pw', $owner->password));
        $this->assertNotSame('dev-secret-pw', $owner->password, 'Password must never be stored in plaintext.');
        $this->assertTrue(Hash::isHashed($owner->password));
    }

    public function test_creates_accounts_and_memberships_via_the_real_services(): void
    {
        $this->artisan('jamesgifford:auth:seed-dev-data')->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $member = User::query()->where('email', 'member@example.test')->firstOrFail();

        $account = $owner->ownedAccounts()->firstOrFail();

        // Single-owner invariant holds (created through AccountService).
        $this->assertTrue($owner->isOwnerOf($account));
        $this->assertSame($owner->id, $account->owner_id);
        $this->assertTrue($member->belongsToAccount($account));
        $this->assertTrue($member->hasRole($account, 'admin'));
    }

    public function test_is_idempotent_and_does_not_duplicate_users(): void
    {
        $this->artisan('jamesgifford:auth:seed-dev-data')->assertSuccessful();
        $this->artisan('jamesgifford:auth:seed-dev-data')->assertSuccessful();

        // updateOrCreate on email — no duplicates, no error, one account.
        $this->assertSame(2, User::query()->count());
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame(1, $owner->ownedAccounts()->count());
    }

    public function test_password_is_sourced_from_config_not_hardcoded(): void
    {
        // Overriding the config value (which reads env) changes the seeded
        // password — proving it isn't hardcoded in the seeder.
        $this->configureDevData(['password' => 'a-completely-different-pw']);

        $this->artisan('jamesgifford:auth:seed-dev-data')->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('a-completely-different-pw', $owner->password));
        $this->assertFalse(Hash::check('dev-secret-pw', $owner->password));
    }

    public function test_command_does_not_run_apply_id_offsets_but_points_to_it(): void
    {
        // It must point at the next step, but not couple to / invoke it.
        $this->artisan('jamesgifford:auth:seed-dev-data')
            ->expectsOutputToContain('jamesgifford:auth:apply-id-offsets')
            ->expectsOutputToContain('does not run it for you')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureDevData(array $overrides = []): void
    {
        config(['jamesgifford.dev-data' => array_merge([
            'environments' => ['local', 'testing'],
            'password' => 'dev-secret-pw',
            'users' => [
                [
                    'name' => 'Dev Owner',
                    'email' => 'owner@example.test',
                    'account' => 'Dev Workspace',
                    'members' => [
                        ['email' => 'member@example.test', 'role' => 'admin'],
                    ],
                ],
                ['name' => 'Dev Member', 'email' => 'member@example.test'],
            ],
        ], $overrides)]);
    }
}
