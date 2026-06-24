<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;

/**
 * One-shot orchestration of a full auth setup. It does NOT reimplement any
 * step — it sequences the existing first-class commands:
 *
 *   1. migrate            (or migrate:fresh with --fresh)
 *   2. jamesgifford:auth:install
 *   3. jamesgifford:auth:seed-dev-data   (only with --with-dev-data)
 *   4. jamesgifford:auth:apply-id-offsets
 *
 * Ordering is deliberate: install (schema + roles) must precede dev-data
 * seeding, and apply-id-offsets runs LAST so the auto-increment counter lands
 * above any rows the seeding just inserted.
 *
 * Safety:
 *  - --fresh runs migrate:fresh (drops ALL tables) and is therefore refused
 *    outright in production — the whole command stops before touching anything.
 *  - Dev-data seeding is double-guarded: it only runs when --with-dev-data is
 *    passed AND the seeder's own environment guard allows it (production is
 *    always refused by the seeder, even with the flag).
 *  - --force is propagated to the underlying migrate and install steps so the
 *    whole sequence can run non-interactively.
 */
final class AuthSetupCommand extends Command
{
    protected $signature = 'jamesgifford:auth:setup
        {--fresh : Reset the database first with migrate:fresh (drops ALL tables). Development only — the whole command refuses in production}
        {--with-dev-data : Also seed deterministic local dev data. Dev/local only — the seeder refuses in production even when this flag is passed}
        {--force : Run non-interactively; propagate --force to the underlying migrate and install steps}';

    protected $description = 'Run a complete auth setup: migrate (or migrate:fresh), install, optionally seed dev data, then apply ID offsets. Sequences the existing commands.';

    public function handle(): int
    {
        $this->info('JamesGifford Auth Setup');
        $this->newLine();

        $fresh = (bool) $this->option('fresh');
        $force = (bool) $this->option('force');
        $withDevData = (bool) $this->option('with-dev-data');

        // --fresh is destructive (migrate:fresh drops every table). Refuse the
        // WHOLE command in production before anything runs — nothing dropped.
        if ($fresh && $this->laravel->environment() === 'production') {
            $this->error('Refusing to run --fresh in a production environment.');
            $this->newLine();
            $this->line('--fresh runs `migrate:fresh`, which DROPS ALL TABLES — a development-only');
            $this->line('convenience. Nothing has been changed. Re-run without --fresh to set up');
            $this->line('additively, or use a real migration to alter a production database.');

            return self::FAILURE;
        }

        // Step 1 — database schema.
        $migrate = $fresh ? 'migrate:fresh' : 'migrate';
        $this->step(1, $fresh
            ? 'Resetting the database (migrate:fresh)'
            : 'Running database migrations (migrate)');
        $code = $this->call($migrate, $force ? ['--force' => true] : []);
        if ($code !== self::SUCCESS) {
            return $this->abort($migrate, $code);
        }

        // Step 2 — install the package (schema additions, public_id lock, roles).
        $this->step(2, 'Installing the auth package (jamesgifford:auth:install)');
        $code = $this->call('jamesgifford:auth:install', $force ? ['--force' => true] : []);
        if ($code !== self::SUCCESS) {
            return $this->abort('jamesgifford:auth:install', $code);
        }

        // Step 3 — optional dev-data seeding (double-guarded: flag + the
        // seeder's own environment check).
        if ($withDevData) {
            $this->step(3, 'Seeding local dev data (jamesgifford:auth:seed-dev-data)');
            $code = $this->call('jamesgifford:auth:seed-dev-data');
            if ($code !== self::SUCCESS) {
                // The seeder declined (e.g. production) or errored; either way it
                // made no changes. Dev data is an optional convenience, so report
                // it and continue rather than failing the whole setup.
                $this->newLine();
                $this->warn('  Dev data was not seeded — the seeder declined (see its message above).');
            }
        } else {
            $this->step(3, 'Seeding local dev data — skipped (pass --with-dev-data to include it)');
        }

        // Step 4 — apply ID offsets LAST, above any seeded rows. No-op when
        // offsets are null or the driver is unsupported.
        $this->step(4, 'Applying ID offsets (jamesgifford:auth:apply-id-offsets)');
        $code = $this->call('jamesgifford:auth:apply-id-offsets');
        if ($code !== self::SUCCESS) {
            return $this->abort('jamesgifford:auth:apply-id-offsets', $code);
        }

        $this->newLine();
        $this->info('Setup complete.');

        return self::SUCCESS;
    }

    /**
     * Print a numbered step header so the order (and any skip) is visible in the
     * output — there are no silent no-ops.
     */
    private function step(int $number, string $message): void
    {
        $this->newLine();
        $this->line("<info>→ Step {$number}/4:</info> {$message}");
    }

    private function abort(string $command, int $code): int
    {
        $this->newLine();
        $this->error("Setup aborted: `{$command}` failed (exit code {$code}). See its output above.");

        return self::FAILURE;
    }
}
