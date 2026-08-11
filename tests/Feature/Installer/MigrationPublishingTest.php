<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Installer\PackageMigrations;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Tests\Support\StagesDatabaseSeeder;
use JamesGifford\Auth\Tests\TestCase;

/**
 * Covers the migration PUBLISHING behaviour: the package's migrations are copied
 * into the consumer's database/migrations as real files with FRESH timestamps
 * generated at publish time, in the correct order, idempotently — and the
 * package never auto-loads them (no loadMigrationsFrom).
 */
class MigrationPublishingTest extends TestCase
{
    use StagesDatabaseSeeder;

    private string $tmpDir;

    private string $lockFilePath;

    private string $migrationsDir;

    /**
     * The stable stems of the package's migrations, in dependency order:
     * users public_id alteration, roles, accounts, users current_account_id
     * alteration, then the account_user pivot.
     *
     * @var list<string>
     */
    private array $expectedStemOrder = [
        'add_jamesgifford_auth_columns_to_users_table',
        'create_account_roles_table',
        'create_accounts_table',
        'add_current_account_id_to_users_table',
        'create_account_user_table',
    ];

    protected function setUp(): void
    {
        if (! isset($this->tmpDir)) {
            $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-migpub-'.uniqid('', true);
            mkdir($this->tmpDir, 0777, true);
            $this->lockFilePath = $this->tmpDir.DIRECTORY_SEPARATOR.'auth.lock.json';
        }
        parent::setUp();
        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        // The full install above wires (or creates) a DatabaseSeeder in the
        // shared testbench skeleton; a leftover file poisons every later test.
        $this->removeDatabaseSeeder();
        $this->rmTree($this->tmpDir);
        if (isset($this->migrationsDir) && is_dir($this->migrationsDir)) {
            foreach ([
                '*jamesgifford*', '*_create_account*', '*_add_jamesgifford*',
                '*_add_current_account*', '*project_widgets*',
            ] as $pattern) {
                foreach ((array) glob($this->migrationsDir.DIRECTORY_SEPARATOR.$pattern) as $f) {
                    @unlink((string) $f);
                }
            }
        }
        if ($this->app !== null) {
            $config = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
            if (is_file($config)) {
                @unlink($config);
            }
        }
        parent::tearDown();
    }

    public function test_install_publishes_migrations_as_real_files_with_fresh_timestamps(): void
    {
        $this->loadLaravelMigrations();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-user-model' => true]);

        // Every package migration exists as a real file in the app's dir.
        foreach ($this->expectedStemOrder as $stem) {
            $matches = glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_'.$stem.'.php') ?: [];
            $this->assertCount(1, $matches, "Expected exactly one published {$stem} file.");

            $name = basename($matches[0]);
            // A fresh, current timestamp prefix — NOT the package's frozen
            // source prefix (2026_05_06_...).
            $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_/', $name);
            $this->assertStringStartsNotWith('2026_05_06_', $name);
        }
    }

    public function test_published_migrations_carry_the_package_warning_comment(): void
    {
        $this->publishFresh();

        $accounts = glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_create_accounts_table.php') ?: [];
        $this->assertNotEmpty($accounts);

        $contents = (string) file_get_contents($accounts[0]);
        $this->assertStringContainsString('Published by the jamesgifford/auth package', $contents);
        $this->assertStringContainsString('prefer a separate migration', $contents);
    }

    public function test_published_timestamps_preserve_the_packages_internal_order(): void
    {
        $this->publishFresh();

        // Read the package migrations back in filename (timestamp) order and
        // confirm their stems match the required dependency order: accounts
        // before the account_user pivot, the users current_account_id
        // alteration after accounts, etc.
        $this->assertSame($this->expectedStemOrder, $this->publishedStemsInFilenameOrder());
    }

    public function test_published_time_portions_start_at_midnight_and_increment_by_position(): void
    {
        $this->publishFresh();

        // In dependency (source) order the time portions must be 000000,
        // 000001, ... — midnight + one second per position.
        $times = [];
        foreach ($this->expectedStemOrder as $stem) {
            $matches = glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_'.$stem.'.php') ?: [];
            $this->assertCount(1, $matches);
            preg_match('/^\d{4}_\d{2}_\d{2}_(\d{6})_/', basename($matches[0]), $m);
            $times[] = $m[1];
        }

        $this->assertSame(['000000', '000001', '000002', '000003', '000004'], $times);
    }

    public function test_package_migrations_sort_before_a_realistic_same_day_project_migration(): void
    {
        $this->publishFresh();

        // The DATE the package stamped is today. A project migration created the
        // same day via make:migration carries a real clock time (here 14:30:00),
        // which must sort AFTER the package's midnight-based migrations.
        $files = array_map('basename', $this->packageFileMap());
        sort($files);
        $latestPackage = end($files);

        preg_match('/^(\d{4}_\d{2}_\d{2})_/', $latestPackage, $m);
        $sameDayProject = $m[1].'_143000_create_project_widgets_table.php';

        $this->assertLessThan(
            $sameDayProject,
            $latestPackage,
            'Every package migration must sort before a same-day 14:30 project migration.',
        );
    }

    public function test_republishing_is_idempotent_and_does_not_clobber(): void
    {
        $this->publishFresh();

        $before = $this->packageFileMap(); // stem => filename

        // Second publish: everything already present → skipped, no new files,
        // and the existing filenames are untouched (not clobbered/renamed).
        $skipped = [];
        $this->app->make(PackageMigrations::class)->publish(function (string $line) use (&$skipped): void {
            if (str_contains($line, 'skipped')) {
                $skipped[] = $line;
            }
        });

        $this->assertCount(count($this->expectedStemOrder), $skipped, 'Every migration should be skipped on re-publish.');
        $this->assertSame($before, $this->packageFileMap(), 'Re-publishing must not clobber or duplicate files.');
    }

    public function test_published_migration_sorts_after_system_and_before_a_project_migration(): void
    {
        // Laravel's system migrations are present (users, etc.). They carry
        // timestamps far in the past, so the freshly-stamped package migrations
        // sort AFTER them (proving the users alteration runs after users exists).
        $this->loadLaravelMigrations();

        $this->publishFresh();

        // A PROJECT migration the developer adds later — a later timestamp, with
        // an account_id FK to the package's accounts table. Its up() asserts
        // accounts already exists, so a successful migrate proves the package's
        // accounts migration ran FIRST.
        $projectFile = $this->migrationsDir.DIRECTORY_SEPARATOR.'9999_12_31_235959_create_project_widgets_table.php';
        file_put_contents($projectFile, $this->projectMigrationSource());

        $accountsFile = basename((glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*_create_accounts_table.php') ?: [''])[0]);
        $this->assertLessThan(
            basename($projectFile),
            $accountsFile,
            'The package accounts migration must sort before the project migration.',
        );

        $exit = Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, $exit, 'Migrations must run cleanly in dependency order: '.Artisan::output());
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertTrue(Schema::hasTable('project_widgets'), 'The project migration referencing accounts should have run after it.');
    }

    public function test_package_does_not_autoload_its_migrations(): void
    {
        // The provider PUBLISHES migrations; it does not loadMigrationsFrom them.
        // On a bare app (only Laravel's system migrations, nothing published),
        // running migrate must NOT create the package tables — proving they are
        // not auto-registered/double-loaded.
        $this->loadLaravelMigrations();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertFalse(Schema::hasTable('accounts'), 'accounts must not be created without publishing (no loadMigrationsFrom).');
        $this->assertSame(0, $this->app->make(PackageMigrations::class)->publishedFileCount());
    }

    // ---- Helpers ----

    protected function defineEnvironment($app): void
    {
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-migpub-'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        $this->lockFilePath = $this->tmpDir.DIRECTORY_SEPARATOR.'auth.lock.json';
        $this->migrationsDir = $app->databasePath('migrations');

        $app['config']->set('jamesgifford.auth.public_id.lock_file_path', $this->lockFilePath);
        $app['config']->set('jamesgifford.auth.models.user', User::class);

        $connection = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.foreign_key_constraints", true);
    }

    private function publishFresh(): void
    {
        $this->app->make(PackageMigrations::class)->publish(fn (string $line) => null);
    }

    /**
     * The package's published migration stems in filename (timestamp) order.
     *
     * @return list<string>
     */
    private function publishedStemsInFilenameOrder(): array
    {
        $wanted = array_flip($this->expectedStemOrder);
        $files = glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files);

        $stems = [];
        foreach ($files as $file) {
            $stem = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename((string) $file, '.php'));
            if (isset($wanted[$stem])) {
                $stems[] = $stem;
            }
        }

        return $stems;
    }

    /**
     * Map of package stem => published basename currently on disk.
     *
     * @return array<string, string>
     */
    private function packageFileMap(): array
    {
        $wanted = array_flip($this->expectedStemOrder);
        $map = [];
        foreach (glob($this->migrationsDir.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $base = basename((string) $file, '.php');
            $stem = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $base);
            if (isset($wanted[$stem])) {
                $map[$stem] = basename((string) $file);
            }
        }
        ksort($map);

        return $map;
    }

    private function projectMigrationSource(): string
    {
        return <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                if (! Schema::hasTable('accounts')) {
                    throw new RuntimeException('accounts table must exist before this project migration runs');
                }

                Schema::create('project_widgets', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('project_widgets');
            }
        };
        PHP;
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
