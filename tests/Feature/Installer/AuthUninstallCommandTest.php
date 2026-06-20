<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Tests\TestCase;
use ReflectionClass;

class AuthUninstallCommandTest extends TestCase
{
    private const ACK = '--i-understand-this-will-delete-all-auth-data';

    private string $tmpDir;

    private string $lockFilePath;

    private string $migrationsDir;

    protected function setUp(): void
    {
        if (! isset($this->tmpDir)) {
            $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-uninstall-'.uniqid('', true);
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
            foreach (['*jamesgifford*', '*_create_account*', '*_add_jamesgifford*', '*_add_current_account*'] as $pattern) {
                foreach ((array) glob($this->migrationsDir.DIRECTORY_SEPARATOR.$pattern) as $f) {
                    @unlink((string) $f);
                }
            }
        }
        if ($this->app !== null) {
            $published = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
            if (is_file($published)) {
                @unlink($published);
            }
        }
        parent::tearDown();
    }

    // ---- Acknowledgment gate ----

    public function test_refuses_without_acknowledgment_flag(): void
    {
        $this->stageInstall();

        $this->artisan('jamesgifford:auth:uninstall')
            ->expectsOutputToContain('Uninstall removes the auth package from this application')
            ->expectsOutputToContain('--i-understand-this-will-delete-all-auth-data')
            ->assertExitCode(1);

        // Nothing changed.
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertFileExists($this->lockFilePath);
    }

    // ---- Production guard ----

    public function test_refuses_in_production_without_force_production(): void
    {
        $this->stageInstall();
        $this->app['env'] = 'production';

        $exit = Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);
        $output = Artisan::output();
        $this->app['env'] = 'testing';

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing to run uninstall in a production environment', $output);
        $this->assertTrue(Schema::hasTable('accounts'));
    }

    public function test_proceeds_in_production_with_force_production(): void
    {
        $this->stageInstall();
        $this->app['env'] = 'production';

        $exit = Artisan::call('jamesgifford:auth:uninstall', [
            self::ACK => true,
            '--force-production' => true,
            '--force' => true,
        ]);
        $output = Artisan::output();
        $this->app['env'] = 'testing';

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Uninstall complete.', $output);
        $this->assertFalse(Schema::hasTable('accounts'));
    }

    // ---- Data-loss summary ----

    public function test_data_loss_summary_shows_real_counts(): void
    {
        $this->stageInstall();

        $owner = $this->insertUser('usr_owner00000000000a');
        $member = $this->insertUser('usr_member0000000000b');
        $a1 = $this->insertAccount('acc_aaaaaaaaaaaaaaaaaa', $owner);
        $a2 = $this->insertAccount('acc_bbbbbbbbbbbbbbbbbb', $owner);
        $ownerRole = (int) DB::table('account_roles')->where('key', 'owner')->value('id');
        $this->insertMembership($a1, $owner, $ownerRole);
        $this->insertMembership($a2, $owner, $ownerRole);
        $this->insertMembership($a1, $member, $ownerRole);
        $this->insertCustomRole('auditor');

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('2 accounts', $output);
        $this->assertStringContainsString('3 memberships', $output);
        $this->assertStringContainsString('1 custom role (auditor)', $output);
        $this->assertStringContainsString('public_id and current_account_id columns from 2 users', $output);
        $this->assertStringContainsString('5 published migration files', $output);
    }

    public function test_data_loss_summary_handles_partial_install_gracefully(): void
    {
        // Lock file present, but the package tables were never created.
        $this->writeLockFile();
        $this->loadLaravelMigrations();

        $exit = Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('accounts table not found — skipping', $output);
        $this->assertStringContainsString('Uninstall complete.', $output);
        // The lock file that did exist was removed.
        $this->assertFileDoesNotExist($this->lockFilePath);
    }

    // ---- Confirmation ----

    public function test_wrong_confirmation_input_cancels_cleanly(): void
    {
        $this->stageInstall();

        $this->artisan('jamesgifford:auth:uninstall', [self::ACK => true])
            ->expectsQuestion('Type "uninstall" to confirm, or anything else to cancel', 'no thanks')
            ->expectsOutputToContain('Uninstall canceled. Nothing was changed.')
            ->assertExitCode(0);

        // Nothing changed.
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertFileExists($this->lockFilePath);
    }

    public function test_correct_confirmation_input_proceeds(): void
    {
        $this->stageInstall();

        $this->artisan('jamesgifford:auth:uninstall', [self::ACK => true])
            ->expectsQuestion('Type "uninstall" to confirm, or anything else to cancel', 'uninstall')
            ->expectsOutputToContain('Uninstall complete.')
            ->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('accounts'));
    }

    public function test_force_skips_confirmation_but_still_tears_down(): void
    {
        $this->stageInstall();

        // No expectsQuestion: --force must not prompt.
        $this->artisan('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true])
            ->expectsOutputToContain('This will permanently delete:')
            ->expectsOutputToContain('Uninstall complete.')
            ->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('accounts'));
    }

    // ---- Surgical teardown ----

    public function test_rolls_back_only_package_migrations(): void
    {
        $this->stageInstall();

        Schema::create('consumer_widgets', function ($table) {
            $table->id();
            $table->string('label');
        });
        DB::table('migrations')->insert([
            'migration' => '2026_01_01_000000_create_consumer_widgets_table',
            'batch' => 99,
        ]);

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);

        $this->assertTrue(Schema::hasTable('consumer_widgets'));
        $this->assertTrue(
            DB::table('migrations')->where('migration', '2026_01_01_000000_create_consumer_widgets_table')->exists()
        );
    }

    public function test_drops_package_tables_and_removes_user_columns(): void
    {
        $this->stageInstall();

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);

        $this->assertFalse(Schema::hasTable('accounts'));
        $this->assertFalse(Schema::hasTable('account_roles'));
        $this->assertFalse(Schema::hasTable('account_user'));
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasColumn('users', 'public_id'));
        $this->assertFalse(Schema::hasColumn('users', 'current_account_id'));
    }

    public function test_deletes_published_migration_files(): void
    {
        $this->stageInstall();
        $this->assertNotEmpty(glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_create_accounts_table.php'));

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);

        $this->assertSame([], glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_create_accounts_table.php'));
        $this->assertSame([], glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_create_account_user_table.php'));
    }

    public function test_deletes_lock_file(): void
    {
        $this->stageInstall();
        $this->assertFileExists($this->lockFilePath);

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);

        $this->assertFileDoesNotExist($this->lockFilePath);
    }

    // ---- Config file handling ----

    public function test_config_file_preserved_by_default(): void
    {
        $this->stageInstall();
        $configFile = $this->writePublishedConfig();

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertFileExists($configFile);
        $this->assertStringContainsString('left the published config file in place', $output);
    }

    public function test_config_file_removed_with_delete_config_flag(): void
    {
        $this->stageInstall();
        $configFile = $this->writePublishedConfig();

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true, '--delete-config' => true]);

        $this->assertFileDoesNotExist($configFile);
    }

    // ---- User model ----

    public function test_does_not_modify_user_model_and_prints_manual_instructions(): void
    {
        $this->stageInstall();

        $userFile = (new ReflectionClass(User::class))->getFileName();
        $before = (string) file_get_contents((string) $userFile);

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);
        $output = Artisan::output();

        // The User model file is untouched.
        $this->assertSame($before, (string) file_get_contents((string) $userFile));

        // Instructions name the real file and the traits to remove.
        $this->assertStringContainsString('One manual step remains', $output);
        $this->assertStringContainsString((string) $userFile, $output);
        $this->assertStringContainsString('use JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId;', $output);
        $this->assertStringContainsString('use JamesGifford\\Auth\\Concerns\\HasAccounts;', $output);
    }

    public function test_user_model_instructions_tailor_to_absent_traits(): void
    {
        $this->stageInstall();

        // Point at a User model that has no package traits.
        $class = 'PlainUninstallUser';
        $fqcn = 'JamesGifford\\Auth\\Tests\\Support\\Tmp\\'.$class;
        $path = $this->tmpDir.DIRECTORY_SEPARATOR.$class.'.php';
        file_put_contents($path, $this->plainUserSource($class));
        require $path;
        config(['jamesgifford.auth.models.user' => $fqcn]);

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('no longer references the package\'s traits', $output);
        $this->assertStringNotContainsString('One manual step remains', $output);
    }

    public function test_completion_message_renders_cleanly(): void
    {
        $this->stageInstall();

        Artisan::call('jamesgifford:auth:uninstall', [self::ACK => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Uninstall complete.', $output);
        $this->assertStringContainsString('tables, columns, migration files, and public ID lock', $output);
    }

    // ---- Setup helpers ----

    protected function defineEnvironment($app): void
    {
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-uninstall-'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        $this->lockFilePath = $this->tmpDir.DIRECTORY_SEPARATOR.'auth.lock.json';
        $this->migrationsDir = $app->databasePath('migrations');

        $app['config']->set('jamesgifford.auth.public_id.lock_file_path', $this->lockFilePath);
        $app['config']->set('jamesgifford.auth.models.user', User::class);

        $connection = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.foreign_key_constraints", true);
    }

    private function stageInstall(): void
    {
        $this->writeLockFile();
        $this->copyPackageMigrationsToTestbenchPath();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->app->make(AccountRoleSeeder::class)->run();
    }

    private function writeLockFile(): void
    {
        $config = $this->app->make(PublicIdConfig::class);
        $fingerprint = $this->app->make(ConfigFingerprint::class);
        $this->app->make(LockFile::class)->write($config, $fingerprint->compute($config));
    }

    private function writePublishedConfig(): string
    {
        $dir = config_path('jamesgifford');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir.DIRECTORY_SEPARATOR.'auth.php';
        file_put_contents($file, "<?php\n\n// consumer config\nreturn [];\n");

        return $file;
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

    private function insertAccount(string $publicId, int $ownerId): int
    {
        return (int) DB::table('accounts')->insertGetId([
            'public_id' => $publicId,
            'name' => 'Acme',
            'owner_id' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMembership(int $accountId, int $userId, int $roleId): void
    {
        DB::table('account_user')->insert([
            'account_id' => $accountId,
            'user_id' => $userId,
            'account_role_id' => $roleId,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCustomRole(string $key): void
    {
        DB::table('account_roles')->insert([
            'key' => $key,
            'name' => ucfirst($key),
            'description' => 'Custom',
            'system' => false,
            'sort_order' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function plainUserSource(string $class): string
    {
        return "<?php\n\n".
            "namespace JamesGifford\\Auth\\Tests\\Support\\Tmp;\n\n".
            "use Illuminate\\Foundation\\Auth\\User as Authenticatable;\n\n".
            "class {$class} extends Authenticatable\n".
            "{\n".
            "    protected \$table = 'users';\n".
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
}
