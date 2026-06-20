<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use Closure;
use Illuminate\Contracts\Foundation\Application;

/**
 * Single source of truth for identifying and tearing down the package's own
 * migrations in a consuming application.
 *
 * Both the install command's --fresh mode and the uninstall command rely on
 * this so there is exactly one definition of "the package's migrations" — by
 * filename, from the package's own database/migrations directory. The published
 * copies in the consumer's database/migrations keep these exact names, which is
 * what makes surgical rollback and file deletion possible without touching any
 * non-package migration.
 */
final class PackageMigrations
{
    public function __construct(private readonly Application $app) {}

    /**
     * Canonical migration names (basename without .php), sourced from the
     * package's own database/migrations directory, sorted by filename so the
     * order matches their timestamp prefixes.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $source = __DIR__.'/../../database/migrations';
        $files = glob($source.DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files);

        return array_map(static fn (string $f): string => basename($f, '.php'), $files);
    }

    /**
     * Roll back ONLY the package's own migrations, in reverse order, so tables
     * drop and columns are removed while non-package migrations are left
     * untouched. Migrations that aren't recorded as run are skipped gracefully.
     *
     * @param  callable(string):void  $log  receives per-migration progress lines
     * @param  callable(string):void  $warn  receives warnings (e.g. missing file)
     */
    public function rollback(callable $log, callable $warn): void
    {
        $migrator = $this->app->make('migrator');
        $repository = $migrator->getRepository();

        if (! $repository->repositoryExists()) {
            $log('  - no migration repository; nothing to roll back');

            return;
        }

        $ran = $repository->getRan();

        // resolvePath() is protected but handles php-parser's anonymous-class
        // migration files correctly (including the require cache), so bind into
        // the migrator to reuse it rather than re-requiring files ourselves.
        $resolve = Closure::bind(
            fn (string $path) => $this->resolvePath($path),
            $migrator,
            $migrator::class,
        );

        foreach (array_reverse($this->names()) as $name) {
            if (! in_array($name, $ran, true)) {
                $log("  - {$name} (not run; skipped)");

                continue;
            }

            $path = $this->app->databasePath('migrations'.DIRECTORY_SEPARATOR.$name.'.php');
            if (! is_file($path)) {
                $warn("  - {$name}: file missing from database/migrations; cannot roll back its schema");

                continue;
            }

            $migration = $resolve($path);
            if (is_object($migration) && method_exists($migration, 'down')) {
                $migration->down();
            }
            $repository->delete((object) ['migration' => $name]);
            $log("  - rolled back {$name}");
        }
    }

    /**
     * Delete the consumer's published copies of the package migrations. Skips
     * any that are already absent.
     *
     * @param  callable(string):void  $log
     */
    public function deletePublishedFiles(callable $log): void
    {
        foreach ($this->names() as $name) {
            $path = $this->app->databasePath('migrations'.DIRECTORY_SEPARATOR.$name.'.php');
            if (is_file($path)) {
                @unlink($path);
                $log("  - removed published migration {$name}.php");
            }
        }
    }

    /**
     * Count how many of the package's published migration files currently exist
     * in the consumer's database/migrations directory.
     */
    public function publishedFileCount(): int
    {
        $count = 0;
        foreach ($this->names() as $name) {
            if (is_file($this->app->databasePath('migrations'.DIRECTORY_SEPARATOR.$name.'.php'))) {
                $count++;
            }
        }

        return $count;
    }
}
