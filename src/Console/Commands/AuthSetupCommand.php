<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Auth\Database\IdOffsetManager;

/**
 * One-shot orchestration of a full auth setup. It does NOT reimplement any
 * step — it sequences the existing first-class commands:
 *
 *   1. migrate            (or migrate:fresh with --fresh)
 *   2. jamesgifford:auth:install   (preceded by an interactive educational pause)
 *   3. jamesgifford:auth:seed-dev-data   (only with --with-dev-data)
 *   4. jamesgifford:auth:apply-id-offsets
 *
 * Ordering is deliberate: install (schema + roles) must precede dev-data
 * seeding, and apply-id-offsets runs LAST so the auto-increment counter lands
 * above any rows the seeding just inserted.
 *
 * Interactivity is gated: when run interactively (no --force), it publishes the
 * config and PAUSES — before the irreversible public_id lock — to explain the
 * lock and how to set ID offsets (config literal or env var). --force (or a
 * non-interactive terminal) skips the pause; install itself is always invoked
 * non-interactively, so the pause is the single human touch-point.
 *
 * Safety (NOT bypassed by --force):
 *  - --fresh runs migrate:fresh (drops ALL tables) and is therefore refused
 *    outright in production — the whole command stops before touching anything.
 *  - Dev-data seeding is double-guarded: it only runs when --with-dev-data is
 *    passed AND the seeder's own environment guard allows it (production is
 *    always refused by the seeder, even with the flag).
 *  - --force only suppresses the interactive pause and is propagated to the
 *    migrate step so the sequence can run unattended.
 */
final class AuthSetupCommand extends Command
{
    protected $signature = 'jamesgifford:auth:setup
        {--fresh : Reset the database first with migrate:fresh (drops ALL tables). Development only — the whole command refuses in production}
        {--with-dev-data : Also seed deterministic local dev data. Dev/local only — the seeder refuses in production even when this flag is passed}
        {--force : Run non-interactively: skip the educational pause and propagate --force to the migrate step}';

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

        // Publish the config first (never overwriting an existing one) so it can
        // be reviewed/edited BEFORE the irreversible public_id lock that install
        // performs. The educational pause sits between the two.
        $this->publishConfig();

        if (! $force && $this->input->isInteractive()) {
            $this->educationalPause();
        }

        // install is always invoked non-interactively: the pause above is this
        // command's single interactive touch-point, so install never re-prompts.
        $code = $this->call('jamesgifford:auth:install', ['--force' => true]);
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
     * Publish config/jamesgifford/auth.php if it is not already present. Never
     * passes --force, so an existing (possibly customized) config — including a
     * --fresh run's surviving config — is left untouched.
     */
    private function publishConfig(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');

        if (is_file($target)) {
            $this->line('  - config already present (left untouched): config/jamesgifford/auth.php');

            return;
        }

        $this->callSilent('vendor:publish', ['--tag' => 'jamesgifford-auth-config']);
        $this->line('  - published config to config/jamesgifford/auth.php');
    }

    /**
     * Interactive-only pause shown after the config is published and BEFORE the
     * public_id lock. It explains the irreversible lock and shows copy/paste
     * snippets for declaring ID offsets two ways — a config literal and an
     * environment variable — then waits for the user to continue.
     */
    private function educationalPause(): void
    {
        $usersEnv = IdOffsetManager::envKeyFor('users');
        $accountsEnv = IdOffsetManager::envKeyFor('accounts');

        $this->newLine();
        $this->line(str_repeat('─', 72));
        $this->line('  Before the public_id format is locked');
        $this->line(str_repeat('─', 72));
        $this->newLine();
        $this->line('The public_id configuration in <info>config/jamesgifford/auth.php</info> is about to');
        $this->line('be LOCKED. After locking, changing the format would invalidate every ID');
        $this->line('already generated — so review that file now if you have not.');
        $this->newLine();
        $this->line('ID offsets make real records start above a chosen number (reserving the');
        $this->line('low id range for seeded dev data). Set them EITHER way — config reads');
        $this->line('the env vars, and a literal you write in config takes precedence:');
        $this->newLine();
        $this->line('  • In your .env:');
        $this->newLine();
        $this->line("        {$usersEnv}=11");
        $this->line("        {$accountsEnv}=1001");
        $this->newLine();
        $this->line('  • Or as a literal in config/jamesgifford/auth.php:');
        $this->newLine();
        $this->line("        'id_offsets' => [");
        $this->line("            'users' => 11,");
        $this->line("            'accounts' => 1001,");
        $this->line('        ],');
        $this->newLine();
        $this->line('They are applied in the final step. To set them now, press Ctrl-C, edit');
        $this->line('config or .env, then re-run; otherwise continue.');
        $this->newLine();

        $this->ask('Press ENTER to continue (locking public_id and finishing setup)');
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
