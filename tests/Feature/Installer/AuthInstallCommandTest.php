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
