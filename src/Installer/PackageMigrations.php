<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Single source of truth for publishing, identifying, and tearing down the
 * package's own migrations in a consuming application.
 *
 * The package does NOT loadMigrationsFrom() its migrations — they are PUBLISHED
 * into the consumer's database/migrations as real files, exactly like the
 * developer's own migrations, so they run in the normal merged-timestamp order
 * alongside Laravel's system migrations and the project's own.
 *
 * Publishing assigns FRESH timestamps generated at publish time (see publish()),
 * so the published filenames deliberately DIFFER from the package's frozen
 * source filenames. Because of that, this class identifies the package's own
 * published migrations by their STEM — the descriptive part after the
 * `YYYY_MM_DD_HHMMSS_` timestamp prefix — NOT by exact filename. The stems
 * (add_jamesgifford_auth_columns_to_users_table, create_accounts_table, …) are
 * distinctive and stable across fresh timestamps, which is what lets --fresh and
 * uninstall find and remove the published copies reliably.
 */
final class PackageMigrations
{
    /**
     * A marker embedded in every published migration file, so a human (or tool)
     * can recognise a package-published migration even after it has been given a
     * fresh timestamp filename.
     */
    private const WARNING_MARKER = 'Published by the jamesgifford/auth package';

    public function __construct(private readonly Application $app) {}

    /**
     * Canonical migration names (basename without .php), sourced from the
     * package's own database/migrations directory, sorted by filename so the
     * order matches their timestamp prefixes — which is the correct internal
     * dependency order (accounts before the account_user pivot, the users
     * current_account_id alteration after accounts exists, etc.).
     *
     * @return list<string>
     */
    public function names(): array
    {
        $files = glob($this->sourceDir().DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files);

        return array_map(static fn (string $f): string => basename($f, '.php'), $files);
    }

    /**
     * The stems of the package's migrations, in dependency order — the stable
     * identifiers used to recognise published copies regardless of the fresh
     * timestamp prefix they were published with.
     *
     * @return list<string>
     */
    public function stems(): array
    {
        return array_map(fn (string $name): string => $this->stemFor($name), $this->names());
    }

    /**
     * Publish the package's migrations into the consumer's database/migrations
     * directory with FRESH timestamps generated NOW.
     *
     * Why fresh timestamps: the package's frozen source timestamps are fixed in
     * the past and could sort incorrectly against the consuming app's migrations
     * — most critically, the users-table ALTERATION must sort after Laravel's
     * users-table CREATION. Stamping today's DATE (which, at setup time, is after
     * the fresh app's system migrations) places them correctly. Each successive
     * package migration is stamped one second later than the previous, so their
     * correct internal order (by source filename) is preserved and never
     * ambiguous.
     *
     * Idempotent: a migration whose stem is already published is skipped, never
     * overwritten or duplicated (publish-if-absent, skip-if-present). Skipped
     * ones are reported through $log.
     *
     * @param  callable(string):void  $log  receives per-migration progress lines
     */
    public function publish(callable $log): void
    {
        $targetDir = $this->app->databasePath('migrations');
        if (! is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        $alreadyPublished = $this->publishedFiles();

        // Use today's DATE but start the TIME portion at midnight (000000),
        // incrementing one second per source position (000000, 000001, ...).
        // Midnight makes the package's migrations sort as early as possible
        // within the publish day, so they precede any project migration created
        // the SAME day — make:migration stamps a real time-of-day, which is
        // always later than 000000. There are only a handful of package
        // migrations, so the sequence stays within the first few seconds after
        // midnight, nowhere near a realistic clock time. The position-based
        // increment preserves the package's internal dependency order.
        $base = Carbon::now()->startOfDay();
        $position = 0;

        foreach ($this->names() as $name) {
            $stem = $this->stemFor($name);
            $offset = $position;
            $position++;

            if (isset($alreadyPublished[$stem])) {
                $log(sprintf(
                    '  - skipped %s (already published: %s)',
                    $stem,
                    basename($alreadyPublished[$stem]),
                ));

                continue;
            }

            $timestamp = $base->copy()->addSeconds($offset)->format('Y_m_d_His');
            $filename = $timestamp.'_'.$stem.'.php';
            $target = $targetDir.DIRECTORY_SEPARATOR.$filename;

            $contents = (string) file_get_contents($this->sourceDir().DIRECTORY_SEPARATOR.$name.'.php');
            file_put_contents($target, $this->annotate($contents));

            $log('  - published '.$filename);
        }
    }

    /**
     * Roll back ONLY the package's own migrations, in reverse order, so tables
     * drop and columns are removed while non-package migrations are left
     * untouched. The (idempotent) down() runs for every package migration even
     * if it is not recorded as run, so leftover schema from a broken teardown
     * — a migrations table out of sync with the actual columns/tables — is
     * still removed; the migrations-table row is only deleted when present.
     *
     * The down() logic is resolved from the package's OWN source migrations,
     * not the consumer's published copies. The published copies can be stale
     * (the package was updated but not re-published) or already deleted, and we
     * always want the current teardown logic regardless.
     *
     * Because published files carry fresh timestamps, the name recorded in the
     * migrations table differs from the package's source filename — so the
     * recorded row is matched by STEM, not by exact name, and that recorded name
     * (not the source name) is what gets deleted from the repository.
     *
     * A single migration's down() failing does NOT abort the whole teardown:
     * each is attempted independently so one bad migration can never strand the
     * columns another would have removed (e.g. users.public_id, which rolls
     * back last). If any failed, a summarizing exception is thrown once every
     * migration has been attempted.
     *
     * @param  callable(string):void  $log  receives per-migration progress lines
     * @param  callable(string):void  $warn  receives warnings (e.g. a failed down())
     */
    public function rollback(callable $log, callable $warn): void
    {
        $migrator = $this->app->make('migrator');
        $repository = $migrator->getRepository();

        if (! $repository->repositoryExists()) {
            $log('  - no migration repository; nothing to roll back');

            return;
        }

        // Map each ran migration to its stem, so a package migration recorded
        // under a fresh-timestamp filename can be matched to (and its row
        // deleted for) the package's source migration.
        $ranByStem = [];
        foreach ($repository->getRan() as $ranName) {
            $ranByStem[$this->stemFor($ranName)] = $ranName;
        }

        // resolvePath() is protected but handles php-parser's anonymous-class
        // migration files correctly (including the require cache), so bind into
        // the migrator to reuse it rather than re-requiring files ourselves.
        $resolve = Closure::bind(
            fn (string $path) => $this->resolvePath($path),
            $migrator,
            $migrator::class,
        );

        $failures = [];

        foreach (array_reverse($this->names()) as $name) {
            $stem = $this->stemFor($name);
            $recordedName = $ranByStem[$stem] ?? null;

            try {
                $migration = $resolve($this->sourceDir().DIRECTORY_SEPARATOR.$name.'.php');
                if (is_object($migration) && method_exists($migration, 'down')) {
                    // down() is idempotent (drops only what exists), so we run
                    // it whether or not the migration is recorded as run. A
                    // broken teardown can leave the schema physically present
                    // while the migrations table lost its row — and uninstall
                    // must still remove that leftover schema (e.g. a stranded
                    // users.public_id column) rather than skip it.
                    $migration->down();
                }

                if ($recordedName !== null) {
                    $repository->delete((object) ['migration' => $recordedName]);
                    $log("  - rolled back {$name}");
                } else {
                    $log("  - {$name} (not recorded; dropped any leftover schema)");
                }
            } catch (Throwable $e) {
                // Keep going: a later migration (e.g. the one that removes
                // users.public_id) must still get its chance to roll back. A
                // recorded row is left in place since its rollback did not
                // complete.
                $failures[$name] = $e->getMessage();
                $warn("  - {$name}: rollback failed ({$e->getMessage()}); continuing");
            }
        }

        if ($failures !== []) {
            $detail = implode('; ', array_map(
                static fn (string $name, string $message): string => "{$name}: {$message}",
                array_keys($failures),
                array_values($failures),
            ));

            throw new RuntimeException(
                count($failures).' package migration(s) could not be rolled back — '.$detail
            );
        }
    }

    /**
     * Delete the consumer's published copies of the package migrations —
     * identified by stem, so fresh-timestamp filenames are matched correctly.
     * Skips any that are already absent.
     *
     * @param  callable(string):void  $log
     */
    public function deletePublishedFiles(callable $log): void
    {
        foreach ($this->publishedFiles() as $path) {
            @unlink($path);
            $log('  - removed published migration '.basename($path));
        }
    }

    /**
     * Count how many of the package's published migration files currently exist
     * in the consumer's database/migrations directory.
     */
    public function publishedFileCount(): int
    {
        return count($this->publishedFiles());
    }

    /**
     * Map of stem => full path for each of the package's migrations that is
     * currently published in the consumer's database/migrations directory.
     *
     * Identification is by STEM (the descriptive suffix after the
     * `YYYY_MM_DD_HHMMSS_` prefix), so it recognises copies published with fresh
     * timestamps as well as any legacy copies that still carry the package's
     * original frozen filenames.
     *
     * @return array<string, string>
     */
    public function publishedFiles(): array
    {
        $wanted = array_flip($this->stems());
        $found = [];

        $dir = $this->app->databasePath('migrations');
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            $stem = $this->stemFor(basename($path, '.php'));
            if (isset($wanted[$stem]) && ! isset($found[$stem])) {
                $found[$stem] = $path;
            }
        }

        return $found;
    }

    /**
     * Strip the `YYYY_MM_DD_HHMMSS_` migration timestamp prefix, leaving the
     * stable descriptive stem. A name without a recognisable prefix is returned
     * unchanged (it simply won't match any package stem).
     */
    private function stemFor(string $basename): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $basename) ?? $basename;
    }

    /**
     * Insert the "published by this package" warning comment near the top of a
     * migration's source, just after the declare(strict_types=1); line (or the
     * opening tag if that line is absent). Idempotent — never doubles up.
     */
    private function annotate(string $contents): string
    {
        if (str_contains($contents, self::WARNING_MARKER)) {
            return $contents;
        }

        $comment = "\n/*\n"
            .' * '.self::WARNING_MARKER." (jamesgifford:auth:install).\n"
            ." * This table structure is expected by the package's models and services.\n"
            ." * Modify with caution — prefer a separate migration over editing this file.\n"
            ." */\n";

        if (preg_match('/^declare\(strict_types=1\);$/m', $contents) === 1) {
            return preg_replace('/^(declare\(strict_types=1\);)$/m', '$1'.$comment, $contents, 1) ?? $contents;
        }

        return preg_replace('/^(<\?php)$/m', '$1'.$comment, $contents, 1) ?? $contents;
    }

    /**
     * The package's own migrations directory — the single source of truth for
     * both the canonical migration names/stems and the teardown (down()) logic.
     */
    private function sourceDir(): string
    {
        return __DIR__.'/../../database/migrations';
    }
}
