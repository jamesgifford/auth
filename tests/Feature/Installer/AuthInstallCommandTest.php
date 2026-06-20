<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\ConfigGuard;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\PublicId;
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
