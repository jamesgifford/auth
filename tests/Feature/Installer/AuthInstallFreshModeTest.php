<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\ConfigGuard;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Tests\TestCase;
use ReflectionClass;

class AuthInstallFreshModeTest extends TestCase
{
    private string $tmpDir;

    private string $lockFilePath;

    private string $migrationsDir;

    protected function setUp(): void
    {
        if (! isset($this->tmpDir)) {
            $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-fresh-'.uniqid('', true);
            mkdir($this->tmpDir, 0777, true);
            $this->lockFilePath = $this->tmpDir.DIRECTORY_SEPARATOR.'auth.lock.json';
        }
        parent::setUp();
        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
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
        $publishedConfig = $this->app !== null ? config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php') : null;
        if ($publishedConfig !== null && is_file($publishedConfig)) {
            @unlink($publishedConfig);
        }
        parent::tearDown();
    }

    // ---- Happy path ----

    public function test_fresh_on_clean_post_install_state_succeeds(): void
    {
        $this->stagePostInstallState();

        Artisan::call('jamesgifford:auth:install', ['--fresh' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Tearing down existing package setup', $output);
        $this->assertStringContainsString('rolled back 2026_05_06_100004_create_account_user_table', $output);
        $this->assertStringContainsString('All checks passed.', $output);

        // The schema is fully present again after the redo.
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertTrue(Schema::hasColumn('users', 'public_id'));
    }

    public function test_fresh_with_force_skips_confirmation_prompt(): void
    {
        $this->stagePostInstallState();

        // No expectsConfirmation registered — if --force didn't suppress the
        // prompt, the command would hang / fail.
        $this->artisan('jamesgifford:auth:install', ['--fresh' => true, '--force' => true])
            ->assertSuccessful();
    }

    public function test_fresh_recreates_the_public_id_lock_file(): void
    {
        $this->stagePostInstallState();
        $this->assertFileExists($this->lockFilePath);

        Artisan::call('jamesgifford:auth:install', ['--fresh' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('reset public ID configuration lock', $output);
        $this->assertStringContainsString('Re-locking public ID configuration', $output);
        // The lock was deleted during teardown and rewritten from current config.
        $this->assertFileExists($this->lockFilePath);
        $this->assertSame('Locked', $this->freshGuard()->status()->name);
    }

    public function test_fresh_displays_config_and_confirms_before_relocking(): void
    {
        $this->stagePostInstallState();
        config(['jamesgifford.auth.public_id.body.length' => 22]);

        Artisan::call('jamesgifford:auth:install', ['--fresh' => true, '--force' => true]);
        $output = Artisan::output();

        // Same display-and-confirm as the normal path, sourced from config.
        $this->assertStringContainsString('Configuration (from config/jamesgifford/auth.php):', $output);
        $this->assertStringContainsString('Body length         22', $output);
        $this->assertStringContainsString('All checks passed.', $output);
    }

    public function test_fresh_declining_config_gate_does_not_tear_down(): void
    {
        $this->stagePostInstallState();

        $this->artisan('jamesgifford:auth:install', ['--fresh' => true])
            ->expectsOutputToContain('Configuration (from config/jamesgifford/auth.php):')
            ->expectsConfirmation('Proceed with this configuration?', 'no')
            ->expectsOutputToContain('Fresh reinstall canceled. Edit config/jamesgifford/auth.php and re-run.')
            ->assertSuccessful();

        // Declining before teardown leaves the existing install intact.
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertFileExists($this->lockFilePath);
    }

    // ---- Data-safety refusals ----

    public function test_fresh_refuses_when_accounts_has_rows(): void
    {
        $this->stagePostInstallState();
        $ownerId = $this->insertUser('usr_owner000000000000');
        DB::table('accounts')->insert([
            'public_id' => 'acc_test0000000000000',
            'name' => 'Acme',
            'owner_id' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('jamesgifford:auth:install', ['--fresh' => true, '--force' => true])
            ->expectsOutputToContain('package-owned data exists')
            ->expectsOutputToContain("the 'accounts' table has 1 row(s)")
            ->assertExitCode(1);

        // Nothing was torn down.
        $this->assertTrue(Schema::hasTable('accounts'));
    }

    public function test_fresh_refuses_when_a_user_has_a_public_id(): void
    {
        $this->stagePostInstallState();
        $this->insertUser('usr_haspublicid000000');

        $this->artisan('jamesgifford:auth:install', ['--fresh' => true, '--force' => true])
            ->expectsOutputToContain('package-owned data exists')
            ->expectsOutputToContain('user(s) have a non-null public_id')
            ->assertExitCode(1);
    }

    public function test_fresh_refuses_when_custom_roles_exist(): void
    {
        $this->stagePostInstallState();
        DB::table('account_roles')->insert([
            'key' => 'billing',
            'name' => 'Billing Manager',
            'description' => 'Custom consumer role',
            'system' => false,
            'sort_order' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('jamesgifford:auth:install', ['--fresh' => true, '--force' => true])
            ->expectsOutputToContain('package-owned data exists')
            ->expectsOutputToContain('custom (non-system) role(s)')
            ->assertExitCode(1);
    }

    public function test_fresh_does_not_refuse_when_only_system_roles_exist(): void
    {
        // stagePostInstallState seeds only the system roles from config.
        $this->stagePostInstallState();
        $this->assertSame(0, DB::table('account_roles')->where('system', false)->count());
        $this->assertGreaterThan(0, DB::table('account_roles')->where('system', true)->count());

        $this->artisan('jamesgifford:auth:install', ['--fresh' => true, '--force' => true])
            ->expectsOutputToContain('All checks passed.')
            ->assertSuccessful();
    }

    public function test_fresh_refuses_in_production(): void
    {
        $this->stagePostInstallState();
        $this->app['env'] = 'production';

        $exit = Artisan::call('jamesgifford:auth:install', ['--fresh' => true, '--force' => true]);
        $output = Artisan::output();

        // Restore the environment before Testbench's teardown runs migrate
        // rollback (which itself refuses to run unprompted in production).
        $this->app['env'] = 'testing';

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('refuses to run in production', $output);

        // No teardown happened despite --force.
        $this->assertTrue(Schema::hasTable('accounts'));
    }

    // ---- Surgical teardown ----

    public function test_fresh_rolls_back_only_package_migrations(): void
    {
        $this->stagePostInstallState();

        // A non-package migration / table the consumer owns.
        Schema::create('consumer_widgets', function ($table) {
            $table->id();
            $table->string('label');
        });
        DB::table('migrations')->insert([
            'migration' => '2026_01_01_000000_create_consumer_widgets_table',
            'batch' => 99,
        ]);

        $this->artisan('jamesgifford:auth:install', ['--fresh' => true, '--force' => true])
            ->assertSuccessful();

        // The non-package table and its migration record are untouched.
        $this->assertTrue(Schema::hasTable('consumer_widgets'));
        $this->assertTrue(
            DB::table('migrations')->where('migration', '2026_01_01_000000_create_consumer_widgets_table')->exists()
        );
    }

    public function test_fresh_deletes_old_published_migrations_and_republishes_without_duplicates(): void
    {
        $this->stagePostInstallState();

        Artisan::call('jamesgifford:auth:install', ['--fresh' => true, '--force' => true]);

        // Exactly one copy of each package migration in database/migrations.
        $accounts = glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_create_accounts_table.php') ?: [];
        $this->assertCount(1, $accounts, 'Expected exactly one create_accounts_table migration file.');

        $roles = glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_create_account_roles_table.php') ?: [];
        $this->assertCount(1, $roles);
    }

    public function test_fresh_preserves_the_published_config_file(): void
    {
        $this->stagePostInstallState();

        $configDir = config_path('jamesgifford');
        if (! is_dir($configDir)) {
            mkdir($configDir, 0777, true);
        }
        $configFile = $configDir.DIRECTORY_SEPARATOR.'auth.php';
        file_put_contents($configFile, "<?php\n\n// consumer-edited config\nreturn [];\n");

        Artisan::call('jamesgifford:auth:install', ['--fresh' => true, '--force' => true]);

        $this->assertFileExists($configFile);
        $this->assertStringContainsString('consumer-edited config', (string) file_get_contents($configFile));
    }

    // ---- User model handling ----

    public function test_fresh_leaves_existing_user_model_traits_intact(): void
    {
        $this->stagePostInstallState();

        $userFile = (new ReflectionClass(User::class))->getFileName();
        $before = (string) file_get_contents((string) $userFile);

        Artisan::call('jamesgifford:auth:install', ['--fresh' => true, '--force' => true]);

        $after = (string) file_get_contents((string) $userFile);

        // Untouched: no removal, no duplication.
        $this->assertSame($before, $after);
        $this->assertSame(1, substr_count($after, 'use HasPublicId;'));
        $this->assertSame(1, substr_count($after, 'use HasAccounts;'));
    }

    public function test_fresh_prints_public_id_prefix_mismatch_advisory(): void
    {
        $this->stagePostInstallState();

        // Config maps the user model to a prefix the model does not return.
        config(['jamesgifford.auth.public_id.prefixes' => [User::class => 'foo']]);

        $userFile = (new ReflectionClass(User::class))->getFileName();
        $before = (string) file_get_contents((string) $userFile);

        Artisan::call('jamesgifford:auth:install', ['--fresh' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString("publicIdPrefix() returns 'usr'", $output);
        $this->assertStringContainsString('update app/Models/User.php manually', $output);

        // Advisory only — the model is not modified.
        $this->assertSame($before, (string) file_get_contents((string) $userFile));
    }

    // ---- Setup helpers ----

    protected function defineEnvironment($app): void
    {
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-fresh-'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        $this->lockFilePath = $this->tmpDir.DIRECTORY_SEPARATOR.'auth.lock.json';
        $this->migrationsDir = $app->databasePath('migrations');

        $app['config']->set('jamesgifford.auth.public_id.lock_file_path', $this->lockFilePath);
        $app['config']->set('jamesgifford.auth.models.user', User::class);

        $connection = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.foreign_key_constraints", true);
    }

    private function stagePostInstallState(): void
    {
        $this->writeLockFile();
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->app->make(AccountRoleSeeder::class)->run();
    }

    private function insertUser(string $publicId): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'Test User',
            'email' => 'user-'.uniqid('', true).'@example.test',
            'password' => bcrypt('secret'),
            'public_id' => $publicId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function freshGuard(): ConfigGuard
    {
        foreach ([ConfigGuard::class, PublicIdConfig::class, LockFile::class, ConfigFingerprint::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        return $this->app->make(ConfigGuard::class);
    }

    private function writeLockFile(): void
    {
        $this->app->forgetInstance(ConfigGuard::class);
        $config = $this->app->make(PublicIdConfig::class);
        $fingerprint = $this->app->make(ConfigFingerprint::class);
        $this->app->make(LockFile::class)->write($config, $fingerprint->compute($config));
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
}
