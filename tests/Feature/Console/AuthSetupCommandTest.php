<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Tests\TestCase;

/**
 * The setup command ORCHESTRATES the existing first-class commands (migrate,
 * install, seed-dev-data, apply-id-offsets). These tests exercise the real
 * sub-commands end-to-end rather than mocking them, so "reuses, not duplicates"
 * is verified by the actual side effects each underlying command produces.
 *
 * A real users-table migration is written into the scanned database/migrations
 * path (as a real app would have) so the command's own `migrate` / `migrate:fresh`
 * step builds the base schema — install then adds the package schema on top.
 */
class AuthSetupCommandTest extends TestCase
{
    private string $tmpDir;

    private string $lockFilePath;

    private string $migrationsDir;

    protected function setUp(): void
    {
        parent::setUp();
        Model::clearBootedModels();
        $this->writeUsersMigration();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            // Restore env before the parent's migrate rollback runs.
            $this->app['env'] = 'testing';
        }

        $this->rmTree($this->tmpDir);

        if (isset($this->migrationsDir) && is_dir($this->migrationsDir)) {
            $patterns = ['*create_users_table*', '*jamesgifford*', '*_create_account*', '*_add_jamesgifford*', '*_add_current_account*'];
            foreach ($patterns as $pattern) {
                foreach ((array) glob($this->migrationsDir.DIRECTORY_SEPARATOR.$pattern) as $file) {
                    @unlink((string) $file);
                }
            }
        }

        if ($this->app !== null) {
            $this->rmTree(config_path('jamesgifford'));
            foreach (['Account', 'AccountUser', 'AccountRole'] as $model) {
                @unlink($this->app->path('Models'.DIRECTORY_SEPARATOR.$model.'.php'));
            }
        }

        parent::tearDown();
    }

    // ---- Default (no flags): migrate → install → offsets, no dev data ----

    public function test_default_run_migrates_installs_applies_offsets_and_skips_dev_data(): void
    {
        $exit = Artisan::call('jamesgifford:auth:setup', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        // migrate built users; install added the package schema + seeded roles.
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumn('users', 'public_id'));
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertTrue(Schema::hasTable('account_roles'));
        $this->assertGreaterThan(0, DB::table('account_roles')->count(), 'roles seeded by install');

        // No dev data without the flag.
        $this->assertSame(0, DB::table('users')->count());

        // All four steps reported; dev-data explicitly skipped (no silent no-op).
        $this->assertStringContainsString('Step 1/4', $output);
        $this->assertStringContainsString('Step 4/4', $output);
        $this->assertStringContainsString('skipped (pass --with-dev-data to include it)', $output);
    }

    // ---- --with-dev-data in an allowlisted environment seeds ----

    public function test_with_dev_data_seeds_in_an_allowlisted_environment(): void
    {
        $this->app['env'] = 'local'; // in the dev-data allowlist (local, staging)

        $exit = Artisan::call('jamesgifford:auth:setup', ['--with-dev-data' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertTrue(
            DB::table('users')->where('email', 'owner@dev.test')->exists(),
            'dev cast should be seeded when --with-dev-data is used in an allowed env'
        );
        $this->assertStringContainsString('Seeding local dev data', $output);
    }

    // ---- --with-dev-data in production is refused by the SEEDER'S own guard ----

    public function test_with_dev_data_is_refused_by_the_seeders_guard_in_production(): void
    {
        $this->app['env'] = 'production';

        $exit = Artisan::call('jamesgifford:auth:setup', ['--with-dev-data' => true, '--force' => true]);
        $output = Artisan::output();

        // The flag was passed, but the seeder's environment guard still refuses:
        // no dev users created. The core setup itself still succeeded.
        $this->assertSame(0, $exit, $output);
        $this->assertSame(0, DB::table('users')->count(), 'no dev users in production');
        $this->assertFalse(DB::table('users')->where('email', 'owner@dev.test')->exists());
        $this->assertStringContainsString('Refusing to seed dev data in a production environment', $output);
        $this->assertStringContainsString('Dev data was not seeded', $output);
    }

    // ---- --fresh in production refuses the WHOLE command (nothing dropped) ----

    public function test_fresh_is_refused_entirely_in_production_without_dropping_anything(): void
    {
        Schema::create('sentinel_table', function ($table): void {
            $table->id();
        });

        $this->app['env'] = 'production';

        $exit = Artisan::call('jamesgifford:auth:setup', ['--fresh' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing to run --fresh in a production environment', $output);
        $this->assertTrue(Schema::hasTable('sentinel_table'), 'no tables may be dropped when --fresh is refused');
        // Refused before any step ran.
        $this->assertStringNotContainsString('Step 1/4', $output);

        Schema::dropIfExists('sentinel_table');
    }

    // ---- --fresh in local runs migrate:fresh, then the sequence ----

    public function test_fresh_in_local_resets_the_database_then_sets_up(): void
    {
        $this->app['env'] = 'local';

        // Establish an already-migrated database (the real-world precondition
        // for --fresh: migrate:fresh only drops once a migration repository
        // exists).
        $this->assertSame(0, Artisan::call('jamesgifford:auth:setup', ['--force' => true]));
        Artisan::output();

        // A stray table created after migrating; migrate:fresh must drop it.
        Schema::create('stale_table', function ($table): void {
            $table->id();
        });
        $this->assertTrue(Schema::hasTable('stale_table'));

        $exit = Artisan::call('jamesgifford:auth:setup', ['--fresh' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertFalse(Schema::hasTable('stale_table'), 'migrate:fresh should have dropped pre-existing tables');
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumn('users', 'public_id'));
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertStringContainsString('Resetting the database (migrate:fresh)', $output);
    }

    // ---- Ordering: install BEFORE seed BEFORE offsets ----

    public function test_steps_run_in_order_install_then_seed_then_offsets(): void
    {
        $this->app['env'] = 'local';

        Artisan::call('jamesgifford:auth:setup', ['--with-dev-data' => true, '--force' => true]);
        $output = Artisan::output();

        // Distinct step markers, unique to this command's own headers.
        $install = strpos($output, 'Step 2/4');
        $seed = strpos($output, 'Step 3/4');
        $offsets = strpos($output, 'Step 4/4');

        $this->assertNotFalse($install);
        $this->assertNotFalse($seed);
        $this->assertNotFalse($offsets);
        $this->assertTrue($install < $seed, 'install (step 2) must precede dev-data seeding (step 3)');
        $this->assertTrue($seed < $offsets, 'dev-data seeding (step 3) must precede offsets (step 4)');

        // And each step is the command it claims to be.
        $this->assertStringContainsString('jamesgifford:auth:install', $output);
        $this->assertStringContainsString('jamesgifford:auth:seed-dev-data', $output);
        $this->assertStringContainsString('jamesgifford:auth:apply-id-offsets', $output);
    }

    // ---- Skipped steps are clearly reported ----

    public function test_skipped_dev_data_step_is_clearly_reported(): void
    {
        Artisan::call('jamesgifford:auth:setup', ['--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Step 3/4', $output);
        $this->assertStringContainsString('Seeding local dev data — skipped (pass --with-dev-data to include it)', $output);
    }

    // ---- --force is propagated to the underlying migrate/install steps ----

    public function test_force_is_propagated_so_migrate_and_install_run_in_production(): void
    {
        // In production, migrate REQUIRES --force; if setup propagates it, the
        // whole additive sequence runs. This is also the canonical production
        // usage: forward migrate, install, offsets, no dev data.
        $this->app['env'] = 'production';

        $exit = Artisan::call('jamesgifford:auth:setup', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertTrue(Schema::hasTable('users'), 'migrate ran (so --force reached it)');
        $this->assertTrue(Schema::hasTable('accounts'), 'install ran (so --force reached it)');
        $this->assertSame(0, DB::table('users')->count(), 'no dev data in a no-flag run');
    }

    public function test_without_force_migrate_is_blocked_in_production_and_setup_aborts(): void
    {
        $this->app['env'] = 'production';

        $exit = Artisan::call('jamesgifford:auth:setup', []);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Setup aborted', $output);
        $this->assertFalse(Schema::hasTable('accounts'), 'install must not run after migrate is blocked');
    }

    protected function defineEnvironment($app): void
    {
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-setup-'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        $this->lockFilePath = $this->tmpDir.DIRECTORY_SEPARATOR.'auth.lock.json';
        $this->migrationsDir = $app->databasePath('migrations');

        // A file-based SQLite database (not :memory:) so the command's own
        // migrate:fresh genuinely drops and rebuilds tables — :memory: does not
        // survive the drop/reconnect cycle that migrate:fresh performs.
        $database = $this->tmpDir.DIRECTORY_SEPARATOR.'database.sqlite';
        touch($database);
        $app['config']->set('database.default', 'jamesgifford_setup');
        $app['config']->set('database.connections.jamesgifford_setup', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('jamesgifford.auth.public_id.lock_file_path', $this->lockFilePath);
        $app['config']->set('jamesgifford.auth.models.user', User::class);
    }

    // ---- Helpers ----

    private function writeUsersMigration(): void
    {
        if (! is_dir($this->migrationsDir)) {
            mkdir($this->migrationsDir, 0777, true);
        }

        $content = <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                        $table->string('email')->unique();
                        $table->timestamp('email_verified_at')->nullable();
                        $table->string('password');
                        $table->rememberToken();
                        $table->timestamps();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('users');
                }
            };
            PHP;

        file_put_contents(
            $this->migrationsDir.DIRECTORY_SEPARATOR.'0001_01_01_000000_create_users_table.php',
            $content."\n"
        );
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
