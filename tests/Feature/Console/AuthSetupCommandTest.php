<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Console\Commands\AuthSetupCommand;
use JamesGifford\Auth\Database\IdOffsetManager;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModelWithoutOverride;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Tests\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

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

    // ---- Interactive educational pause (after config publish, before lock) ----

    public function test_interactive_run_pauses_with_educational_guidance_before_the_lock(): void
    {
        // No --force, non-production env: the command publishes config, then
        // pauses to teach the public_id lock, the editable prefixes, and the
        // offset options before install performs the irreversible lock.
        // Each expectsOutputToContain must match a DISTINCT output line (Mockery
        // assigns one write per expectation), so assert one substring per line.
        $this->artisan('jamesgifford:auth:setup')
            ->expectsOutputToContain('Before the public_id format is locked')
            // Prefix section: the configured prefixes are part of the locked
            // format, shown with a sample id (the Account model's 'account').
            ->expectsOutputToContain('Public ID prefixes are part of that locked format')
            ->expectsOutputToContain('e.g. account_')
            // Offset section.
            ->expectsOutputToContain("'id_offsets' => [")
            ->expectsOutputToContain(IdOffsetManager::envKeyFor('users').'=11')
            ->expectsOutputToContain(IdOffsetManager::envKeyFor('accounts').'=1001')
            ->expectsQuestion('Press ENTER to continue (locking public_id and finishing setup)', '')
            ->assertExitCode(0);

        // The lock still completed AFTER the pause was acknowledged.
        $this->assertTrue(Schema::hasColumn('users', 'public_id'));
        $this->assertTrue(Schema::hasTable('accounts'));
    }

    public function test_force_run_shows_no_educational_pause_and_uses_configured_prefixes(): void
    {
        $exit = Artisan::call('jamesgifford:auth:setup', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString('Before the public_id format is locked', $output);
        $this->assertStringNotContainsString('Public ID prefixes are part of that locked format', $output);
        $this->assertStringNotContainsString('Press ENTER to continue', $output);

        // It proceeded with the configured prefixes (the Account model's
        // 'account') without pausing: a created account uses that prefix.
        $owner = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $owner->id]);
        $this->assertStringStartsWith('account_', (string) $account->public_id);
    }

    public function test_prefix_reminder_is_shown_before_dev_data_is_seeded(): void
    {
        $this->app['env'] = 'local'; // dev-data allowlisted

        // Interactive run with --with-dev-data: the pause (with the prefix
        // reminder) is step 2 — before the public_id lock and before the
        // step-3 dev-data seeding — so the user can adjust prefixes before any
        // ids (including dev-data ids) are generated under them.
        $this->artisan('jamesgifford:auth:setup', ['--with-dev-data' => true])
            ->expectsOutputToContain('Public ID prefixes are part of that locked format')
            ->expectsQuestion('Press ENTER to continue (locking public_id and finishing setup)', '')
            ->assertExitCode(0);

        // Seeding ran (step 3) — i.e. AFTER the step-2 pause that showed the
        // prefix reminder. (Step order itself is pinned by the ordering test.)
        $this->assertTrue(DB::table('users')->where('email', 'owner@dev.test')->exists());
    }

    public function test_prefix_section_renders_default_user_and_account_prefixes_with_samples(): void
    {
        // Default resolution: a config-mapped user model (no override) => 'user',
        // and the package Account model => 'account'. Rendered deterministically
        // (not through the interactive Q&A), so the full section is asserted.
        config([
            'jamesgifford.auth.models.user' => FixtureModelWithoutOverride::class,
            'jamesgifford.auth.public_id.prefixes' => [
                FixtureModelWithoutOverride::class => 'user',
                Account::class => 'account',
            ],
        ]);
        $this->app->forgetInstance(PublicIdConfig::class);
        $this->app->forgetInstance(PrefixRegistry::class);

        $output = $this->renderPrefixSection();

        $this->assertStringContainsString('Public ID prefixes are part of that locked format', $output);
        $this->assertStringContainsString('config/jamesgifford/auth.php', $output);
        $this->assertMatchesRegularExpression('/users\s+user\s+e\.g\. user_\w+/', $output);
        $this->assertMatchesRegularExpression('/accounts\s+account\s+e\.g\. account_\w+/', $output);
    }

    // ---- ID offsets: read from config AND from env, applied non-interactively ----

    public function test_offsets_declared_in_config_are_read_and_applied_non_interactively(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => 11, 'accounts' => 1001]]);

        $exit = Artisan::call('jamesgifford:auth:setup', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        // On SQLite the ALTER can't run, but the offsets were READ from config
        // (the report says the driver can't honor them, NOT "no offset configured").
        $this->assertStringContainsString('does not support id offsets', $output);
        $this->assertStringNotContainsString('no offset configured', $output);
    }

    public function test_env_sourced_string_offsets_are_read_and_applied_non_interactively(): void
    {
        // config/auth.php reads the JAMESGIFFORD_AUTH_*_ID_OFFSET env vars, which
        // arrive as STRINGS. Simulate that env-sourced config and confirm the
        // command reads + applies them without prompting. (See the dedicated
        // config test for the env var name → config value wiring.)
        config(['jamesgifford.auth.id_offsets' => ['users' => '12345', 'accounts' => '6789']]);

        $exit = Artisan::call('jamesgifford:auth:setup', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('does not support id offsets', $output);
        $this->assertStringNotContainsString('no offset configured', $output);
    }

    // ---- --fresh preserves existing config files (and their values) ----

    public function test_fresh_preserves_an_existing_config_file_and_its_custom_offset(): void
    {
        $this->app['env'] = 'local';

        // Establish a migrated database with a published config.
        $this->assertSame(0, Artisan::call('jamesgifford:auth:setup', ['--force' => true]));
        Artisan::output();

        $configFile = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
        $this->assertFileExists($configFile);

        // Customize it with a literal offset, replacing the env() default.
        $contents = str_replace(
            "'users' => env('JAMESGIFFORD_AUTH_USERS_ID_OFFSET')",
            "'users' => 4242",
            (string) file_get_contents($configFile),
        );
        file_put_contents($configFile, $contents);
        $this->assertStringContainsString("'users' => 4242", (string) file_get_contents($configFile));

        // --fresh resets the DATABASE but must not delete or overwrite config.
        $exit = Artisan::call('jamesgifford:auth:setup', ['--fresh' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertFileExists($configFile);
        $this->assertStringContainsString("'users' => 4242", (string) file_get_contents($configFile));
        $this->assertStringContainsString('config already present (left untouched)', $output);
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

    private function renderPrefixSection(): string
    {
        $command = $this->app->make(AuthSetupCommand::class);
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $buffer = new BufferedOutput;

        $reflection = new ReflectionClass($command);
        $reflection->getProperty('input')->setValue($command, $input);
        $reflection->getProperty('output')->setValue($command, new SymfonyStyle($input, $buffer));
        $reflection->getMethod('displayPrefixSection')->invoke($command);

        return $buffer->fetch();
    }

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
