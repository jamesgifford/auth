<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Database\DevDataSeeder;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Exceptions\DevDataSeedingNotAllowedException;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\SystemRole;
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
            foreach (['auth-dev.php', 'auth.php'] as $name) {
                @unlink(config_path('jamesgifford'.DIRECTORY_SEPARATOR.$name));
            }
        }
        parent::tearDown();
    }

    // ---- Publishing the dev-data config (deliberate, not default-on-install) ----

    public function test_dev_data_config_is_publishable_via_its_own_tag(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth-dev.php');
        @unlink($target);

        Artisan::call('vendor:publish', ['--tag' => 'jamesgifford-auth-dev', '--force' => true]);

        $this->assertFileExists($target);
    }

    public function test_dev_data_config_is_not_published_by_the_default_config_tag(): void
    {
        @unlink(config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth-dev.php'));

        Artisan::call('vendor:publish', ['--tag' => 'jamesgifford-auth-config', '--force' => true]);

        // The main config publish must NOT bring the dev-only file with it.
        $this->assertFileDoesNotExist(config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth-dev.php'));
    }

    public function test_seeding_publishes_the_dev_data_config_on_first_run(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth-dev.php');
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
        $target = $dir.DIRECTORY_SEPARATOR.'auth-dev.php';
        file_put_contents($target, "<?php\n\n// consumer dev marker keep-me-77\nreturn [];\n");

        Artisan::call('jamesgifford:auth:seed-dev-data');
        $output = Artisan::output();

        $this->assertStringContainsString('keep-me-77', (string) file_get_contents($target));
        $this->assertStringNotContainsString('Published dev-data config', $output);
    }

    public function test_a_refused_run_does_not_publish_the_dev_data_config(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth-dev.php');
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

        // firstOrNew keyed on email — no duplicates, no error, one account.
        $this->assertSame(2, User::query()->count());
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame(1, $owner->ownedAccounts()->count());
    }

    // ---- Idempotency across an uninstall → reinstall → re-seed cycle ----

    public function test_reseed_after_uninstall_reinstall_reconciles_leftover_users(): void
    {
        // 1. Seed dev data — the users, accounts and memberships exist.
        $this->app->make(DevDataSeeder::class)->seed();
        $this->assertSame(2, User::query()->count());
        $ownerIdBefore = (int) User::query()->where('email', 'owner@example.test')->value('id');

        // 2. Uninstall: roll back the package schema (drops the accounts tables
        //    and the columns added to users) but LEAVE the user rows — exactly
        //    what the real uninstall does (it never deletes user data).
        $this->uninstallPackageSchema();
        $this->assertFalse(Schema::hasColumn('users', 'public_id'));
        $this->assertSame(2, DB::table('users')->count(), 'user rows survive uninstall');

        // 3. Reinstall: re-add the columns/tables. The users-column migration
        //    backfills a public_id for the leftover rows. Roles are recreated.
        $this->reinstallPackageSchema();
        Model::clearBootedModels();
        $this->seedRoles();

        // 4. Re-seed — this previously failed with a NOT NULL / duplicate-key
        //    error on public_id when the column was re-added to a populated table.
        $this->app->make(DevDataSeeder::class)->seed();

        // Reconciled, not duplicated: same rows reused (found by email).
        $this->assertSame(2, User::query()->count());
        $this->assertSame(
            $ownerIdBefore,
            (int) User::query()->where('email', 'owner@example.test')->value('id'),
            'the leftover owner row is reused, not replaced/duplicated',
        );

        // Every user has a valid public_id.
        foreach (User::query()->get() as $user) {
            $this->assertNotNull($user->public_id, "user {$user->email} has no public_id");
            $this->assertTrue(PublicId::isValid((string) $user->public_id), "invalid public_id: {$user->public_id}");
        }

        // Fresh accounts/memberships attach to the EXISTING (leftover) users.
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame(1, $owner->ownedAccounts()->count());
        $member = User::query()->where('email', 'member@example.test')->firstOrFail();
        $this->assertTrue($member->belongsToAccount($owner->ownedAccounts()->firstOrFail()));
    }

    public function test_leftover_user_without_public_id_gets_one_on_re_seed(): void
    {
        // Simulate a leftover user carrying a NULL public_id (the state a re-add
        // leaves before backfill, or a row the trait's create-only hook never
        // touched). Make the column nullable so we can create that state.
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_id')->nullable()->change();
        });

        $id = DB::table('users')->insertGetId([
            'name' => 'Old Owner',
            'email' => 'owner@example.test', // matches a declared dev user
            'password' => Hash::make('x'),
            'public_id' => null,
        ]);
        $this->assertNull(DB::table('users')->where('id', $id)->value('public_id'));

        $this->app->make(DevDataSeeder::class)->seed();

        // The seeder FOUND the leftover user (no duplicate) and, because it had
        // no public_id, assigned a valid one on the update path.
        $this->assertSame(1, DB::table('users')->where('email', 'owner@example.test')->count());
        $publicId = DB::table('users')->where('id', $id)->value('public_id');
        $this->assertNotNull($publicId);
        $this->assertTrue(PublicId::isValid((string) $publicId));
    }

    public function test_reinstall_migration_backfills_public_id_for_all_existing_users(): void
    {
        // A NON-dev user that predates the package (or survived an uninstall).
        $this->app->make(DevDataSeeder::class)->seed();
        $strayId = DB::table('users')->insertGetId([
            'name' => 'Stray',
            'email' => 'stray@example.test',
            'password' => Hash::make('x'),
            'public_id' => PublicId::generate('user'),
        ]);

        // Uninstall strips public_id from every row; reinstall re-adds it.
        $this->uninstallPackageSchema();
        $this->assertFalse(Schema::hasColumn('users', 'public_id'));

        $this->reinstallPackageSchema();

        // The migration backfilled public_id for ALL pre-existing rows — dev and
        // non-dev alike — so none is left null and the unique index holds.
        $this->assertSame(0, DB::table('users')->whereNull('public_id')->count());
        $stray = DB::table('users')->where('id', $strayId)->first();
        $this->assertNotNull($stray->public_id);
        $this->assertTrue(PublicId::isValid((string) $stray->public_id));

        // And the values are unique across all rows.
        $total = (int) DB::table('users')->count();
        $distinct = (int) DB::table('users')->distinct()->count('public_id');
        $this->assertSame($total, $distinct, 'backfilled public_ids must be unique');
    }

    public function test_password_is_sourced_from_config_not_hardcoded(): void
    {
        // Overriding the config value (which reads env) changes the seeded
        // password — proving it isn't hardcoded in the seeder.
        $this->configureDevData(['users_password' => 'a-completely-different-pw']);

        $this->artisan('jamesgifford:auth:seed-dev-data')->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('a-completely-different-pw', $owner->password));
        $this->assertFalse(Hash::check('dev-secret-pw', $owner->password));
    }

    public function test_warns_after_seeding_with_the_default_password(): void
    {
        $this->configureDevData(['users_password' => 'password']);

        // The warning must come AFTER seeding (it describes what was seeded)
        // and must name the env var — for unattended runs (setup --force) this
        // is the only place the variable is ever surfaced.
        $this->artisan('jamesgifford:auth:seed-dev-data')
            ->expectsOutputToContain('Dev users were seeded with the default password "password".')
            ->expectsOutputToContain('Set JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD in your .env and re-run this')
            ->assertSuccessful();
    }

    public function test_no_default_password_warning_when_a_custom_password_is_configured(): void
    {
        // setUp's configureDevData sets 'dev-secret-pw'.
        $this->artisan('jamesgifford:auth:seed-dev-data')
            ->doesntExpectOutputToContain('default password')
            ->assertSuccessful();
    }

    public function test_warns_when_the_legacy_password_key_is_present_and_ignores_it(): void
    {
        // A pre-1.2.2 published config carries 'password' (renamed to
        // 'users_password'). Silently ignoring it would re-seed every dev user
        // with the default while the consumer believes their configured secret
        // is in effect.
        $this->configureDevData(['password' => 'ignored-legacy-value']);

        $this->artisan('jamesgifford:auth:seed-dev-data')
            ->expectsOutputToContain("The dev config contains the legacy 'password' key, which is ignored.")
            ->expectsOutputToContain("Rename it to 'users_password' in config/jamesgifford/auth-dev.php")
            ->assertSuccessful();

        // The current key still wins; the legacy value is never used.
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('dev-secret-pw', $owner->password));
        $this->assertFalse(Hash::check('ignored-legacy-value', $owner->password));
    }

    public function test_users_password_is_resolved_from_disk_when_missing_from_live_config(): void
    {
        // Simulates the cached-config staleness hazard: a config cache built
        // before 'users_password' existed skips mergeConfigFrom, so the live
        // repository lacks the key entirely. The seeder then re-reads the
        // config from disk, where the package default's env() call resolves
        // the CURRENT environment value — instead of silently seeding the
        // default password while the consumer's .env says otherwise.
        $config = (array) config('jamesgifford.auth-dev');
        unset($config['users_password']);
        config(['jamesgifford.auth-dev' => $config]);

        $_SERVER['JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD'] = 'resolved-from-disk';
        try {
            $this->artisan('jamesgifford:auth:seed-dev-data')
                ->doesntExpectOutputToContain('default password')
                ->assertSuccessful();
        } finally {
            unset($_SERVER['JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD']);
        }

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('resolved-from-disk', $owner->password));
    }

    public function test_command_does_not_run_apply_id_offsets_but_points_to_it(): void
    {
        // It must point at the next step, but not couple to / invoke it.
        $this->artisan('jamesgifford:auth:seed-dev-data')
            ->expectsOutputToContain('jamesgifford:auth:apply-id-offsets')
            ->expectsOutputToContain('does not run it for you')
            ->assertSuccessful();
    }

    // ---- New schema: explicit accounts, per-user memberships, current_account ----

    public function test_user_with_multiple_memberships_belongs_to_multiple_accounts(): void
    {
        $this->configureDevData([
            'accounts' => [
                ['name' => 'Acme', 'owner' => 'owner@example.test'],
                ['name' => 'Beta', 'owner' => 'owner@example.test'],
            ],
            'users' => [
                ['name' => 'Owner', 'email' => 'owner@example.test'],
                ['name' => 'Multi', 'email' => 'multi@example.test', 'memberships' => [
                    ['account' => 'Acme', 'role' => 'admin'],
                    ['account' => 'Beta', 'role' => 'member'],
                ]],
            ],
        ]);

        $this->artisan('jamesgifford:auth:seed-dev-data')->assertSuccessful();

        $multi = User::query()->where('email', 'multi@example.test')->firstOrFail();
        $acme = Account::query()->where('name', 'Acme')->firstOrFail();
        $beta = Account::query()->where('name', 'Beta')->firstOrFail();

        $this->assertSame(2, $multi->accounts()->count());
        $this->assertTrue($multi->hasRole($acme, 'admin'));
        $this->assertTrue($multi->hasRole($beta, 'member'));
    }

    public function test_current_account_is_set_when_declared_and_valid(): void
    {
        $this->configureDevData([
            'accounts' => [
                ['name' => 'Acme', 'owner' => 'owner@example.test'],
                ['name' => 'Beta', 'owner' => 'multi@example.test'],
            ],
            'users' => [
                ['name' => 'Owner', 'email' => 'owner@example.test'],
                ['name' => 'Multi', 'email' => 'multi@example.test', 'memberships' => [
                    ['account' => 'Acme', 'role' => 'member'],
                ], 'current_account' => 'Beta'],
            ],
        ]);

        $this->artisan('jamesgifford:auth:seed-dev-data')->assertSuccessful();

        $multi = User::query()->where('email', 'multi@example.test')->firstOrFail();
        $beta = Account::query()->where('name', 'Beta')->firstOrFail();

        // Belongs to two accounts (owns Beta, member of Acme); active = Beta.
        $this->assertSame(2, $multi->accounts()->count());
        $this->assertSame($beta->id, $multi->current_account_id);
    }

    public function test_current_account_is_ignored_when_user_neither_owns_nor_belongs(): void
    {
        // The user declares a current_account for an account they have no
        // relationship to — validated away, left unset (not blindly assigned).
        $this->configureDevData([
            'accounts' => [
                ['name' => 'Acme', 'owner' => 'owner@example.test'],
            ],
            'users' => [
                ['name' => 'Owner', 'email' => 'owner@example.test'],
                ['name' => 'Outsider', 'email' => 'outsider@example.test', 'current_account' => 'Acme'],
            ],
        ]);

        $this->artisan('jamesgifford:auth:seed-dev-data')->assertSuccessful();

        $outsider = User::query()->where('email', 'outsider@example.test')->firstOrFail();
        $this->assertNull($outsider->current_account_id);
    }

    // ---- Guard fires on the seeder run() path, not only the command ----

    public function test_run_silently_skips_in_production_without_throwing(): void
    {
        $this->app['env'] = 'production';

        try {
            // The DatabaseSeeder / db:seed path calls run() — it must NOT throw.
            $this->app->make(DevDataSeeder::class)->run();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(0, User::query()->count(), 'run() must not seed in production.');
    }

    public function test_run_seeds_in_an_allowed_environment(): void
    {
        $this->app['env'] = 'local';

        try {
            $this->app->make(DevDataSeeder::class)->run();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertTrue(User::query()->where('email', 'owner@example.test')->exists());
    }

    // ---- Callable from a consuming app's DatabaseSeeder ----

    public function test_both_seeders_are_callable_from_a_database_seeder(): void
    {
        $this->app['env'] = 'local';

        $databaseSeeder = new class extends Seeder
        {
            public function run(): void
            {
                $this->call(AccountRoleSeeder::class);

                if (app()->environment('local', 'staging')) {
                    $this->call(DevDataSeeder::class);
                }
            }
        };

        try {
            $databaseSeeder->setContainer($this->app)->run();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertNotNull(AccountRole::findByKey(SystemRole::OWNER), 'roles seed via $this->call');
        $this->assertTrue(User::query()->where('email', 'owner@example.test')->exists(), 'dev data seeds via $this->call');
    }

    public function test_roles_seed_in_production_while_dev_data_self_guards(): void
    {
        $this->app['env'] = 'production';

        try {
            // Roles are required data — seeded in ALL environments.
            $this->app->make(AccountRoleSeeder::class)->run();
            // Dev data self-guards on every invocation path — no throw, no rows.
            $this->app->make(DevDataSeeder::class)->run();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertNotNull(AccountRole::findByKey(SystemRole::OWNER));
        $this->assertSame(0, User::query()->count(), 'dev data must not seed in production');
    }

    /**
     * The package migration files, in filename (run) order.
     *
     * @return list<string>
     */
    private function packageMigrationPaths(): array
    {
        /** @var list<string> $paths */
        $paths = (array) glob(__DIR__.'/../../../database/migrations/*.php');
        sort($paths);

        return $paths;
    }

    /**
     * Simulate uninstall: roll the package migrations back in reverse order,
     * dropping the accounts tables and the columns added to users while leaving
     * the user rows intact. (`require` — not `require_once` — re-executes the
     * file and returns a fresh migration instance each call.)
     */
    private function uninstallPackageSchema(): void
    {
        foreach (array_reverse($this->packageMigrationPaths()) as $path) {
            (require $path)->down();
        }
    }

    /**
     * Simulate reinstall: re-run the package migrations in order.
     */
    private function reinstallPackageSchema(): void
    {
        foreach ($this->packageMigrationPaths() as $path) {
            (require $path)->up();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureDevData(array $overrides = []): void
    {
        config(['jamesgifford.auth-dev' => array_merge([
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
        ], $overrides)]);
    }
}
