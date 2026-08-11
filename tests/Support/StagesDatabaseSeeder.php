<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support;

/**
 * Stages a temporary DatabaseSeeder.php in the testbench skeleton's
 * database/seeders/ directory — the path database_path() resolves to — and
 * guarantees removal.
 *
 * The skeleton directory is shared across the suite, so a test that leaves a
 * file behind poisons every later one. Always call removeDatabaseSeeder() from
 * tearDown, unconditionally.
 */
trait StagesDatabaseSeeder
{
    protected function databaseSeederPath(): string
    {
        return database_path('seeders'.DIRECTORY_SEPARATOR.'DatabaseSeeder.php');
    }

    protected function stageDatabaseSeeder(string $contents): string
    {
        $path = $this->databaseSeederPath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    protected function readDatabaseSeeder(): string
    {
        return (string) file_get_contents($this->databaseSeederPath());
    }

    protected function removeDatabaseSeeder(): void
    {
        @unlink($this->databaseSeederPath());
        @unlink($this->databaseSeederPath().'.bak');
    }

    /**
     * A stock Laravel DatabaseSeeder with an app-owned seeder call, used to
     * prove the package's edits leave unrelated content untouched.
     */
    protected function defaultDatabaseSeederSource(): string
    {
        return <<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            /**
             * Seed the application's database.
             */
            public function run(): void
            {
                // The app's own seeding.
                $this->call(ProductSeeder::class);
            }
        }

        PHP;
    }

    /**
     * A DatabaseSeeder already carrying the seeders `install` wires, for tests
     * that stage a fully-installed state by hand rather than running install.
     */
    protected function wiredDatabaseSeederSource(): string
    {
        return <<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(\JamesGifford\Auth\Database\Seeders\AccountRoleSeeder::class);
                $this->call(\JamesGifford\Auth\Database\Seeders\ApplyIdOffsetsSeeder::class);
            }
        }

        PHP;
    }
}
