<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\ConfigGuard;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\SystemRole;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Tests\TestCase;

class AuthInstallCommandTest extends TestCase
{
    private string $tmpDir;

    private string $lockFilePath;

    private string $migrationsDir;

    private string $userModelPath;

    private string $userModelClass;

    protected function setUp(): void
    {
        // tmpDir and lockFilePath are computed in defineEnvironment but
        // setUp may need them, so make sure they're populated.
        if (! isset($this->tmpDir)) {
            $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-install-'.uniqid('', true);
            mkdir($this->tmpDir, 0777, true);
            $this->lockFilePath = $this->tmpDir.DIRECTORY_SEPARATOR.'auth.lock.json';
        }
        parent::setUp();
        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        if (isset($this->userModelPath)) {
            @unlink($this->userModelPath);
            @unlink($this->userModelPath.'.bak');
        }
        if (isset($this->migrationsDir) && is_dir($this->migrationsDir)) {
            foreach ((array) glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*jamesgifford*') as $f) {
                @unlink((string) $f);
            }
            foreach ((array) glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_create_account*') as $f) {
                @unlink((string) $f);
            }
            foreach ((array) glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_add_jamesgifford*') as $f) {
                @unlink((string) $f);
            }
            foreach ((array) glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_add_current_account*') as $f) {
                @unlink((string) $f);
            }
        }
        if ($this->app !== null) {
            foreach (['auth.php', 'auth.lock.json'] as $name) {
                $file = config_path('jamesgifford'.DIRECTORY_SEPARATOR.$name);
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            foreach (['Account', 'AccountUser', 'AccountRole'] as $model) {
                @unlink($this->app->path('Models'.DIRECTORY_SEPARATOR.$model.'.php'));
            }
        }
        parent::tearDown();
    }

    // ---- Verify mode ----

    public function test_verify_reports_failures_on_fresh_app(): void
    {
        $this->artisan('jamesgifford:auth:install', ['--verify' => true])
            ->expectsOutputToContain('Public ID configuration locked')
            ->expectsOutputToContain('users.public_id column exists')
            ->expectsOutputToContain('One or more checks failed.')
            ->assertExitCode(1);
    }

    public function test_verify_reports_all_passing_when_fully_set_up(): void
    {
        $this->writeLockFile();
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        // Trigger schema check by hitting the package's seeder.
        $this->app->make(AccountRoleSeeder::class)->run();

        $this->artisan('jamesgifford:auth:install', ['--verify' => true])
            ->expectsOutputToContain('All checks passed.')
            ->assertSuccessful();
    }

    /**
     * Regression test for the verification staleness bug: the User model trait
     * checks must read the file from disk (UserModelModifier::analyze) rather
     * than reflecting on the already-loaded class.
     *
     * We load a class that HAS the traits (so reflection would report them as
     * present), then strip the traits from the file on disk. A file-based check
     * reports them missing and fails; a reflection-based check would still see
     * the loaded class's traits and wrongly pass.
     */
    public function test_verification_reads_user_model_from_disk_not_reflection(): void
    {
        // Stage everything else so the User model checks are the only ones that
        // can fail.
        $this->writeLockFile();
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->app->make(AccountRoleSeeder::class)->run();

        $class = 'FileBasedVerifyUser';
        $fqcn = 'JamesGifford\\Auth\\Tests\\Support\\Tmp\\'.$class;
        $this->userModelPath = $this->tmpDir.DIRECTORY_SEPARATOR.$class.'.php';

        // Write + load a version WITH the traits: reflection now sees them.
        file_put_contents($this->userModelPath, $this->userModelSource($class, withTraits: true));
        require $this->userModelPath;

        config(['jamesgifford.auth.models.user' => $fqcn]);

        // Strip the traits from the file. The loaded class is unchanged.
        file_put_contents($this->userModelPath, $this->userModelSource($class, withTraits: false));

        Artisan::call('jamesgifford:auth:install', ['--verify' => true]);
        $output = Artisan::output();

        // Proof it read the file, not the loaded class: the checks fail.
        $this->assertStringContainsString('✗ '.$fqcn.' uses HasPublicId trait', $output);
        $this->assertStringContainsString('✗ '.$fqcn.' uses HasAccounts trait', $output);
        $this->assertStringContainsString('One or more checks failed.', $output);
    }

    public function test_verify_skips_user_model_checks_when_skip_flag_set(): void
    {
        // Set the user model to something unloadable; --skip-user-model means
        // the verify path won't try to inspect it.
        config(['jamesgifford.auth.models.user' => 'App\\Models\\NonexistentUserClass']);

        $this->writeLockFile();
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->app->make(AccountRoleSeeder::class)->run();

        $this->artisan('jamesgifford:auth:install', ['--verify' => true, '--skip-user-model' => true])
            ->expectsOutputToContain('All checks passed.')
            ->assertSuccessful();
    }

    // ---- Preflight ----

    public function test_preflight_fails_when_user_model_class_missing(): void
    {
        config(['jamesgifford.auth.models.user' => 'App\\Models\\NonexistentUserClass']);

        $this->artisan('jamesgifford:auth:install', ['--force' => true])
            ->expectsOutputToContain("User model class 'App\\Models\\NonexistentUserClass' is not loadable")
            ->assertExitCode(1);
    }

    public function test_preflight_fails_when_users_table_missing(): void
    {
        // No migrations run at all — users table doesn't exist.
        $this->artisan('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true])
            ->expectsOutputToContain("'users' table does not exist")
            ->assertExitCode(1);
    }

    // ---- Plan display ----

    public function test_plan_lists_steps_with_skip_indicators(): void
    {
        $this->loadLaravelMigrations();

        $this->artisan('jamesgifford:auth:install', [
            '--skip-public-id' => true,
            '--skip-migrations' => true,
            '--skip-roles' => true,
            '--skip-user-model' => true,
        ])
            ->expectsConfirmation('Proceed?', 'no')
            ->expectsOutputToContain('skipped via flag')
            ->expectsOutputToContain('Installation canceled.')
            ->assertSuccessful();
    }

    public function test_cancellation_at_plan_prompt_returns_success(): void
    {
        $this->loadLaravelMigrations();

        $this->artisan('jamesgifford:auth:install', ['--skip-user-model' => true])
            ->expectsConfirmation('Proceed?', 'no')
            ->expectsOutputToContain('Installation canceled.')
            ->assertSuccessful();
    }

    // ---- Idempotency ----

    public function test_running_with_force_reports_steps_as_done_on_second_run(): void
    {
        $this->writeLockFile();
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->app->make(AccountRoleSeeder::class)->run();

        // Second run: with everything in place and --skip-user-model so we
        // don't touch the fixture, every step should be marked "already".
        $this->artisan('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true])
            ->expectsOutputToContain('already locked')
            ->expectsOutputToContain('already published')
            ->expectsOutputToContain('already run')
            ->expectsOutputToContain('already seeded')
            ->expectsOutputToContain('All checks passed.')
            ->assertSuccessful();
    }

    public function test_verify_after_idempotent_run_still_passes(): void
    {
        $this->writeLockFile();
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->app->make(AccountRoleSeeder::class)->run();

        $this->artisan('jamesgifford:auth:install', ['--verify' => true])
            ->expectsOutputToContain('All checks passed.')
            ->assertSuccessful();
    }

    // ---- Skip flags affect plan ----

    public function test_skip_user_model_does_not_modify_user_model(): void
    {
        $this->writeLockFile();
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // PendingCommand's expectsOutputToContain matches line-by-line and
        // would consume the same line twice if we used both expectations.
        // Run via Artisan facade and inspect the full output string.
        Artisan::call('jamesgifford:auth:install', [
            '--force' => true,
            '--skip-user-model' => true,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('Modify your User model', $output);
        $this->assertStringContainsString('skipped via flag', $output);
    }

    public function test_no_modify_user_alias_works_same_as_skip_user_model(): void
    {
        $this->writeLockFile();
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $this->artisan('jamesgifford:auth:install', ['--force' => true, '--no-modify-user' => true])
            ->expectsOutputToContain('skipped via flag')
            ->assertSuccessful();
    }

    // ---- Config surfacing + auto-publish ----

    public function test_install_publishes_config_when_absent(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
        @unlink($target);
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Published configuration to config/jamesgifford/auth.php', $output);
        $this->assertFileExists($target);
    }

    // ---- Role seeding ----
    //
    // These tests deliberately do NOT use the harness's own role seeding —
    // they start from an UNseeded account_roles table so they actually
    // exercise (and would catch a regression in) install's seeding step.

    public function test_install_seeds_account_roles_from_an_unseeded_table(): void
    {
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertTrue(DB::table('account_roles')->exists(), 'account_roles should be seeded.');
        $this->assertNotNull(AccountRole::findByKey(SystemRole::OWNER));
        $this->assertSame(4, DB::table('account_roles')->count());
        $this->assertStringContainsString('Seeded 4 account roles (owner, admin, member, viewer).', $output);
    }

    public function test_install_runs_migrations_when_package_tables_are_missing_despite_public_id(): void
    {
        // Regression: a partial state where users.public_id exists (and its
        // migration is recorded) but account_roles does not. Previously
        // needsMigrationsRun() only checked users.public_id, so migrations were
        // skipped and seeding hit a non-existent account_roles table.
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();

        Schema::table('users', fn ($table) => $table->string('public_id', 30)->nullable());
        DB::table('migrations')->insert([
            'migration' => '2026_05_06_100000_add_jamesgifford_auth_columns_to_users_table',
            'batch' => 1,
        ]);

        $exit = Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        // The plan now runs migrations (rather than reporting them already run).
        $this->assertStringContainsString('→ Run pending migrations', $output);
        $this->assertTrue(Schema::hasTable('account_roles'));
        $this->assertNotNull(AccountRole::findByKey(SystemRole::OWNER));
        $this->assertStringNotContainsString('Role seeding failed', $output);
    }

    public function test_install_fails_with_a_clear_error_when_account_roles_cannot_be_created(): void
    {
        // The package migrations are recorded as run but their tables were
        // dropped, so `migrate` won't recreate them. Seeding must fail with
        // actionable guidance, not a raw SQL "table not found".
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();

        Schema::table('users', fn ($table) => $table->string('public_id', 30)->nullable());
        Schema::table('users', fn ($table) => $table->unsignedBigInteger('current_account_id')->nullable());
        foreach ([
            '2026_05_06_100000_add_jamesgifford_auth_columns_to_users_table',
            '2026_05_06_100001_create_account_roles_table',
            '2026_05_06_100002_create_accounts_table',
            '2026_05_06_100003_add_current_account_id_to_users_table',
            '2026_05_06_100004_create_account_user_table',
        ] as $migration) {
            DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
        }

        $exit = Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Cannot seed roles', $output);
        $this->assertStringContainsString('migrate:fresh', $output);
        // Actionable message, not a raw database exception.
        $this->assertStringNotContainsString('SQLSTATE', $output);
    }

    public function test_install_seeds_roles_despite_stale_in_process_config(): void
    {
        // Correct config on disk, but the live config repository is stale/empty
        // (the cached-config scenario where mergeConfigFrom was skipped). The
        // seeder must re-read the real roles rather than seed nothing.
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy(__DIR__.'/../../../config/auth.php', $target);
        $this->loadLaravelMigrations();

        config(['jamesgifford.auth.roles' => []]);

        $exit = Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);

        $this->assertSame(0, $exit, 'Install should succeed even with stale in-process roles config.');
        $this->assertNotNull(AccountRole::findByKey(SystemRole::OWNER));
        $this->assertSame(4, DB::table('account_roles')->count());
    }

    public function test_install_reads_overridden_roles_from_published_config_when_in_process_config_is_stale(): void
    {
        // A published config that adds a custom (non-system) role, with stale
        // in-process config. Seeding must reflect the file on disk.
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, $this->configWithCustomRole('auditor'));
        $this->loadLaravelMigrations();

        config(['jamesgifford.auth.roles' => []]);

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);

        $auditor = AccountRole::findByKey('auditor');
        $this->assertNotNull($auditor, 'The custom role from the published config should be seeded.');
        $this->assertFalse((bool) $auditor->system);
        $this->assertNotNull(AccountRole::findByKey(SystemRole::OWNER));
    }

    public function test_running_install_twice_does_not_duplicate_roles(): void
    {
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $this->assertSame(4, DB::table('account_roles')->count());

        // Second run is idempotent (seeder uses updateOrCreate keyed on key).
        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $this->assertSame(4, DB::table('account_roles')->count());
    }

    // ---- ID offsets during install ----

    public function test_install_applies_configured_id_offsets_as_a_final_step(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => 1001]]);
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        // The step runs after seeding; on SQLite it's a reported no-op.
        $this->assertStringContainsString('Applying configured ID offsets', $output);
        $this->assertStringContainsString("accounts: skipped — driver 'sqlite' does not support", $output);
    }

    public function test_install_is_silent_about_offsets_when_none_configured(): void
    {
        // Default config has null offsets — the step must be a silent no-op.
        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => null]]);
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('Applying configured ID offsets', $output);
    }

    // ---- Model publishing during install ----

    public function test_publish_models_flag_publishes_during_install(): void
    {
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', [
            '--force' => true,
            '--skip-user-model' => true,
            '--publish-models' => true,
        ]);

        $this->assertFileExists($this->app->path('Models/Account.php'));
        $this->assertFileExists($this->app->path('Models/AccountUser.php'));
        $this->assertFileExists($this->app->path('Models/AccountRole.php'));
    }

    public function test_install_with_force_but_no_flag_does_not_publish_models(): void
    {
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);

        $this->assertFileDoesNotExist($this->app->path('Models/Account.php'));
    }

    public function test_install_publish_prompt_defaults_to_no(): void
    {
        $this->loadLaravelMigrations();

        $this->artisan('jamesgifford:auth:install', ['--skip-user-model' => true])
            ->expectsConfirmation('Proceed?', 'yes')
            ->expectsConfirmation('Proceed with this configuration?', 'yes')
            ->expectsConfirmation('Publish editable App\\Models subclasses (Account, AccountUser, AccountRole)?', 'no')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->app->path('Models/Account.php'));
    }

    public function test_completion_prints_conditional_boost_reminder(): void
    {
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Using Laravel Boost?', $output);
        $this->assertStringContainsString('php artisan boost:update', $output);
        $this->assertStringContainsString("don't use Boost, no action is needed", $output);
    }

    public function test_install_never_invokes_a_boost_command(): void
    {
        // Boost is not installed in the test environment. If install tried to
        // CALL a boost command, Artisan would throw CommandNotFoundException and
        // this run would fail. A clean exit proves the reminder is text-only.
        $this->loadLaravelMigrations();

        $exit = Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('php artisan boost:update', $output);
    }

    public function test_http_plumbing_is_enabled_by_default_in_published_config(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
        @unlink($target);
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);

        $contents = (string) file_get_contents($target);
        $this->assertMatchesRegularExpression("/'http'\\s*=>\\s*\\[.*?'enabled'\\s*=>\\s*true/s", $contents);
    }

    public function test_without_http_flag_disables_http_in_published_config(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
        @unlink($target);
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', [
            '--force' => true,
            '--skip-user-model' => true,
            '--without-http' => true,
        ]);
        $output = Artisan::output();

        $this->assertFileExists($target);
        $contents = (string) file_get_contents($target);
        $this->assertMatchesRegularExpression("/'http'\\s*=>\\s*\\[.*?'enabled'\\s*=>\\s*false/s", $contents);
        $this->assertStringContainsString('HTTP plumbing disabled', $output);
        $this->assertFalse(config('jamesgifford.auth.http.enabled'));
    }

    public function test_install_does_not_overwrite_existing_config(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        // A consumer-edited config file already in place.
        file_put_contents($target, "<?php\n\n// consumer-edited marker 8f3a\nreturn ['public_id' => ['body' => ['length' => 21]]];\n");
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('Published configuration to', $output);
        $this->assertStringContainsString('consumer-edited marker 8f3a', (string) file_get_contents($target));
    }

    public function test_config_display_reflects_overridden_value(): void
    {
        $this->loadLaravelMigrations();
        // Override after boot: proves the display reads resolved config (and
        // refreshes the config singletons), not hardcoded constants.
        config(['jamesgifford.auth.public_id.body.length' => 24]);

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Body length         24', $output);
    }

    public function test_example_id_in_display_is_a_valid_public_id(): void
    {
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $example = $this->extractExampleId($output);
        $this->assertNotNull($example, 'Expected an Example line in the config display.');
        $this->assertTrue(
            PublicId::isValid($example),
            "Example ID '{$example}' should be a valid public_id."
        );
    }

    public function test_config_display_reflects_value_after_in_process_publish(): void
    {
        // Regression for publish-then-read staleness: the config file is absent
        // (so install auto-publishes it mid-process), and a non-default value is
        // active. The display must reflect the live value, not a stale default.
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
        @unlink($target);
        $this->loadLaravelMigrations();
        config(['jamesgifford.auth.public_id.body.length' => 30]);

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Published configuration to config/jamesgifford/auth.php', $output);
        $this->assertStringContainsString('Body length         30', $output);
    }

    public function test_declining_config_prompt_cancels_without_locking(): void
    {
        $this->loadLaravelMigrations();
        $this->assertFileDoesNotExist($this->lockFilePath);

        $this->artisan('jamesgifford:auth:install', ['--skip-user-model' => true])
            ->expectsConfirmation('Proceed?', 'yes')
            ->expectsOutputToContain('Configuration (from config/jamesgifford/auth.php):')
            ->expectsConfirmation('Proceed with this configuration?', 'no')
            ->expectsOutputToContain('Setup canceled. Edit config/jamesgifford/auth.php and re-run.')
            ->assertSuccessful();

        // The irreversible lock was NOT written.
        $this->assertFileDoesNotExist($this->lockFilePath);
    }

    public function test_confirming_config_prompt_proceeds_to_lock(): void
    {
        $this->loadLaravelMigrations();

        $this->artisan('jamesgifford:auth:install', ['--skip-user-model' => true])
            ->expectsConfirmation('Proceed?', 'yes')
            ->expectsConfirmation('Proceed with this configuration?', 'yes')
            ->expectsConfirmation('Publish editable App\\Models subclasses (Account, AccountUser, AccountRole)?', 'no')
            ->expectsOutputToContain('All checks passed.')
            ->assertSuccessful();

        $this->assertFileExists($this->lockFilePath);
    }

    public function test_force_skips_config_prompt_but_still_displays_and_locks(): void
    {
        $this->loadLaravelMigrations();

        // No expectsConfirmation: --force must not prompt. The display still prints.
        $this->artisan('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true])
            ->expectsOutputToContain('Configuration (from config/jamesgifford/auth.php):')
            ->expectsOutputToContain('All checks passed.')
            ->assertSuccessful();

        $this->assertFileExists($this->lockFilePath);
    }

    public function test_completion_message_notes_the_lock_file_and_resolved_path(): void
    {
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Installation complete.', $output);
        $this->assertStringContainsString('The public ID format is now locked', $output);
        $this->assertStringContainsString('Commit it to version control', $output);

        // The resolved lock path (customized to a tmp path via config in
        // defineEnvironment) appears verbatim — proving it's resolved, not a
        // hardcoded literal.
        $this->assertStringContainsString($this->lockFilePath, $output);

        // The old next-steps checklist is gone.
        $this->assertStringNotContainsString('php artisan test', $output);
        $this->assertStringNotContainsString('AccountService::class)->create', $output);
    }

    public function test_completion_message_shows_project_relative_lock_path(): void
    {
        $this->loadLaravelMigrations();

        // Use the default, in-project lock location instead of the tmp path.
        $absolute = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.lock.json');
        config(['jamesgifford.auth.public_id.lock_file_path' => $absolute]);

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);
        $output = Artisan::output();

        $relative = 'config'.DIRECTORY_SEPARATOR.'jamesgifford'.DIRECTORY_SEPARATOR.'auth.lock.json';
        $this->assertStringContainsString($relative, $output);
        // The absolute path (with the project root prefix) is not shown.
        $this->assertStringNotContainsString($absolute, $output);
    }

    protected function defineEnvironment($app): void
    {
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-install-'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        $this->lockFilePath = $this->tmpDir.DIRECTORY_SEPARATOR.'auth.lock.json';
        $this->migrationsDir = $app->databasePath('migrations');

        $app['config']->set('jamesgifford.auth.public_id.lock_file_path', $this->lockFilePath);
        $app['config']->set('jamesgifford.auth.models.user', User::class);

        // Sqlite + foreign keys for the full-install path.
        $connection = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.foreign_key_constraints", true);
    }

    private function extractExampleId(string $output): ?string
    {
        if (preg_match('/Example\s+(\S+)/', $output, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function configWithCustomRole(string $key): string
    {
        return <<<PHP
        <?php

        return [
            'roles' => [
                'owner' => ['name' => 'Owner', 'system' => true, 'sort_order' => 1],
                'admin' => ['name' => 'Administrator', 'system' => true, 'sort_order' => 2],
                'member' => ['name' => 'Member', 'system' => true, 'sort_order' => 3],
                'viewer' => ['name' => 'Viewer', 'system' => true, 'sort_order' => 4],
                '{$key}' => ['name' => 'Auditor', 'system' => false, 'sort_order' => 5],
            ],
        ];
        PHP;
    }

    private function userModelSource(string $class, bool $withTraits): string
    {
        $imports = $withTraits
            ? "use JamesGifford\\Auth\\Concerns\\HasAccounts;\n".
              "use JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId;\n"
            : '';

        $body = $withTraits
            ? "    use HasAccounts;\n".
              "    use HasPublicId;\n\n".
              "    protected \$table = 'users';\n\n".
              "    public function publicIdPrefix(): string\n".
              "    {\n".
              "        return 'usr';\n".
              "    }\n"
            : "    protected \$table = 'users';\n";

        return "<?php\n\n".
            "namespace JamesGifford\\Auth\\Tests\\Support\\Tmp;\n\n".
            "use Illuminate\\Foundation\\Auth\\User as Authenticatable;\n".
            $imports.
            "\nclass {$class} extends Authenticatable\n".
            "{\n".
            $body.
            "}\n";
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function writeLockFile(): void
    {
        $this->app->forgetInstance(ConfigGuard::class);
        $config = $this->app->make(PublicIdConfig::class);
        $fingerprint = $this->app->make(ConfigFingerprint::class);
        $this->app->make(LockFile::class)->write($config, $fingerprint->compute($config));
        // Clear the cached guard state.
        $this->app->forgetInstance(ConfigGuard::class);
    }

    private function copyPackageMigrationsToTestbenchPath(): void
    {
        if (! is_dir($this->migrationsDir)) {
            mkdir($this->migrationsDir, 0777, true);
        }
        $source = __DIR__.'/../../../database/migrations';
        foreach ((array) glob($source.DIRECTORY_SEPARATOR.'*.php') as $file) {
            copy((string) $file, $this->migrationsDir.DIRECTORY_SEPARATOR.basename((string) $file));
        }
    }

    private function runPackageMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->artisan('migrate', ['--path' => 'database/migrations', '--realpath' => true])
            ->run();
    }
}
