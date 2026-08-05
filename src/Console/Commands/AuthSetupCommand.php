<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Auth\Database\DevDataSeeder;
use JamesGifford\Auth\Database\IdOffsetManager;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\PublicId\PublicId;
use Throwable;

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
 * above any rows the seeding just inserted. install is therefore invoked with
 * --skip-id-offsets — it would otherwise apply offsets at Step 2, before the
 * dev data is seeded, pushing the dev fixtures up to the offset instead of the
 * low ids. Offsets are applied exactly once, here in Step 4.
 *
 * Interactivity is gated: when run interactively (no --force), it publishes the
 * config and PAUSES — before the irreversible public_id lock — to explain the
 * lock and how to set ID offsets (config literal or env var). --force (or a
 * non-interactive terminal) skips the pause; install itself is always invoked
 * non-interactively, so the pause is the single human touch-point.
 *
 * Safety (evaluated up front, before Step 1 and before any prompt):
 *  - --fresh runs migrate:fresh (drops ALL tables) and is therefore refused
 *    outright in production — even with --force — and the whole command stops
 *    before touching anything.
 *  - In production without --force the command aborts immediately: the migrate
 *    step needs --force there (Laravel will not migrate a production database
 *    unconfirmed), so setup decides that itself rather than letting migrate
 *    pause for confirmation it can't satisfy unattended.
 *  - Dev-data seeding is double-guarded: it only runs when --with-dev-data is
 *    passed AND the seeder's own environment guard allows it (production is
 *    always refused by the seeder, even with the flag).
 *  - --force only suppresses the interactive pause and is propagated to the
 *    migrate step so the sequence can run unattended.
 *  - With --with-dev-data, a pre-flight (before Step 1) refuses any configured
 *    id offset that is <= the number of dev records for that table: the dev
 *    fixtures take ids 1..N, so an offset there could never start real records
 *    above them. It ERRORS (rather than silently no-opping) with nothing yet
 *    migrated or seeded. Only runs where the seeder would actually seed.
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
        $isProduction = $this->laravel->environment() === 'production';

        // Hard safety guards run FIRST — before Step 1 and before any interactive
        // prompt or pause. The command must never prompt the user and only then
        // discover it is in production; it decides up front and aborts cleanly.

        // --fresh is destructive (migrate:fresh drops every table). Refuse the
        // WHOLE command in production before anything runs — nothing dropped.
        if ($fresh && $isProduction) {
            $this->error('Refusing to run --fresh in a production environment.');
            $this->newLine();
            $this->line('--fresh runs `migrate:fresh`, which DROPS ALL TABLES — a development-only');
            $this->line('convenience. Nothing has been changed. Re-run without --fresh to set up');
            $this->line('additively, or use a real migration to alter a production database.');

            return self::FAILURE;
        }

        // In production the migrate step requires --force: Laravel's migrate
        // command refuses to touch a production database without confirmation,
        // and would PROMPT for it. Resolve that here, before the call, so the
        // command aborts cleanly instead of stalling on migrate's interactive
        // confirmation (and never reaches the educational pause either).
        if ($isProduction && ! $force) {
            $this->newLine();
            $this->error('Setup aborted: running migrations in production requires --force.');
            $this->newLine();
            $this->line('Laravel will not migrate a production database without confirmation, so');
            $this->line('setup cannot run unattended here. Re-run non-interactively with --force to');
            $this->line('apply migrations, install, and offsets in one pass:');
            $this->newLine();
            $this->line('    php artisan jamesgifford:auth:setup --force');

            return self::FAILURE;
        }

        // Pre-flight: when dev data will be seeded, a configured id offset must
        // leave room ABOVE the dev fixtures — the dev records take ids 1..N, and
        // an offset <= N could never start real records above them. Validate
        // BEFORE Step 1 so an unusable offset fails fast, with nothing migrated
        // or seeded (no partial work) and a clear message to fix the config.
        if ($withDevData) {
            $code = $this->validateDevDataOffsets();
            if ($code !== self::SUCCESS) {
                return $code;
            }
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
        // --publish-models so a full setup also writes the editable App\Models
        // subclasses (install skips publishing under --force without this).
        // --skip-id-offsets so install does NOT apply offsets here (before any
        // dev-data seeding); setup applies them itself as its final Step 4, after
        // seeding, so dev fixtures keep the low ids and the offset lands above.
        $code = $this->call('jamesgifford:auth:install', [
            '--force' => true,
            '--publish-models' => true,
            '--skip-id-offsets' => true,
        ]);
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
        $this->displayNextSteps();

        return self::SUCCESS;
    }

    /**
     * Post-setup pointers. Install prints its own next steps mid-run at
     * Step 2, where they scroll away; the DatabaseSeeder wiring otherwise
     * appears only in the README. Surfacing both at the END of setup — the
     * documented primary entry point — is what an operator actually sees.
     */
    private function displayNextSteps(): void
    {
        $this->newLine();
        $this->line('Next steps:');
        $this->newLine();
        $this->line('  • Register the seeders in database/seeders/DatabaseSeeder.php so');
        $this->line('    `migrate:fresh --seed` recreates roles (and the dev cast locally):');
        $this->newLine();
        $this->line('        // Required account roles — ALL environments.');
        $this->line('        $this->call(\JamesGifford\Auth\Database\Seeders\AccountRoleSeeder::class);');
        $this->newLine();
        $this->line('        // Dev fixtures — the seeder itself refuses outside local/staging.');
        $this->line('        $this->call(\JamesGifford\Auth\Database\DevDataSeeder::class);');
        $this->newLine();
        $this->line('  • Using Laravel Boost? Run `php artisan boost:update` to install this');
        $this->line("    package's AI skill (first-time Boost setup uses `boost:install`).");
        $this->line('    Not using Boost? No action needed.');
    }

    /**
     * Pre-flight validation for the --with-dev-data path: a configured id offset
     * must be GREATER THAN the number of dev records for that table, because the
     * dev fixtures occupy ids 1..N and the offset (the auto-increment start for
     * real records) cannot be set at or below them.
     *
     *  - offset > N   → valid (real records start above the fixtures, with a gap)
     *  - offset == N+1 → valid (real records start immediately after the fixtures)
     *  - offset <= N   → INVALID (collision with dev ids 1..N) → abort
     *
     * Runs only when dev data will ACTUALLY seed (the flag AND an allowed
     * environment): in a non-seeding environment no dev rows are inserted, so no
     * offset can collide and there is nothing to validate. A null offset is
     * skipped (the feature is disabled); a malformed offset (non-integer or < 1)
     * is ERRORED here rather than left to fail at the final apply step, so the
     * whole class of offset-config mistakes is caught before anything is mutated.
     *
     * The counts are the number of rows the seeder will ACTUALLY insert, not the
     * raw declaration counts: users are deduplicated by email (firstOrNew) and an
     * account is only seeded when it has a name and an owner that resolves to a
     * declared user — matching {@see DevDataSeeder::seed()}.
     *
     * The check assumes the dev fixtures land on ids 1..N, which holds for the
     * intended use — a fresh/empty database (with or without --fresh). It cannot
     * query the users table here (it may not exist until Step 1's migrate), so it
     * validates against the config, not existing rows.
     */
    private function validateDevDataOffsets(): int
    {
        // Only meaningful when the seeder would actually run here. Its env guard
        // is non-throwing and touches no database, so it is safe this early.
        if (! $this->laravel->make(DevDataSeeder::class)->environmentAllowed()) {
            return self::SUCCESS;
        }

        $devCounts = $this->effectiveDevRecordCounts();

        /** @var array<string, mixed> $offsets */
        $offsets = (array) config('jamesgifford.auth.id_offsets', []);

        foreach ($devCounts as $table => $count) {
            $offset = IdOffsetManager::normalizeOffset($offsets[$table] ?? null);

            // Null disables the offset — nothing to validate.
            if ($offset === null) {
                continue;
            }

            // Fail fast on a malformed offset (e.g. a non-numeric typo in the
            // env var). Left unchecked it would pass here and only throw at the
            // final apply-id-offsets step — AFTER migrate and dev-data seeding
            // have already run, defeating this pre-flight's whole purpose.
            if (! is_int($offset) || $offset < 1) {
                $this->newLine();
                $this->error(sprintf(
                    'The configured %s id offset must be a positive integer, got %s.',
                    $table,
                    var_export($offsets[$table] ?? null, true),
                ));
                $this->newLine();
                $this->line(sprintf(
                    'Set a positive integer (%s) or remove it to disable the offset.',
                    IdOffsetManager::envKeyFor($table),
                ));
                $this->newLine();
                $this->line('Nothing has been changed — fix the offset and re-run.');

                return self::FAILURE;
            }

            if ($offset <= $count) {
                $this->newLine();
                $this->error(sprintf(
                    'The configured %s id offset (%d) must be greater than the number of dev-data %s (%d).',
                    $table,
                    $offset,
                    $table,
                    $count,
                ));
                $this->newLine();
                $this->line(sprintf(
                    'The offset reserves ids above the dev fixtures; an offset of %d collides with dev %s at ids 1-%d.',
                    $offset,
                    $table,
                    $count,
                ));
                $this->line(sprintf(
                    'Set the %s offset to at least %d (%s), or higher to leave a gap.',
                    $table,
                    $count + 1,
                    IdOffsetManager::envKeyFor($table),
                ));
                $this->newLine();
                $this->line('Nothing has been changed — fix the offset and re-run.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * The number of dev rows the seeder will actually insert per table, matching
     * {@see DevDataSeeder::seed()}: users deduplicated by (non-empty) email, and
     * accounts deduplicated by name and counted only when they have a name and an
     * owner that resolves to a declared user. Counting raw declarations instead
     * would over-count (duplicate emails, unnamed/orphaned accounts) and could
     * falsely reject an offset that is in fact above every seeded id.
     *
     * @return array{users: int, accounts: int}
     */
    private function effectiveDevRecordCounts(): array
    {
        /** @var list<array<string, mixed>> $userDeclarations */
        $userDeclarations = (array) config('jamesgifford.auth-dev.users', []);
        /** @var list<array<string, mixed>> $accountDeclarations */
        $accountDeclarations = (array) config('jamesgifford.auth-dev.accounts', []);

        $userEmails = [];
        foreach ($userDeclarations as $declaration) {
            $email = (string) ($declaration['email'] ?? '');
            if ($email !== '') {
                $userEmails[$email] = true;
            }
        }

        $accountNames = [];
        foreach ($accountDeclarations as $declaration) {
            $name = (string) ($declaration['name'] ?? '');
            $owner = (string) ($declaration['owner'] ?? '');
            if ($name !== '' && isset($userEmails[$owner])) {
                $accountNames[$name] = true;
            }
        }

        return [
            'users' => count($userEmails),
            'accounts' => count($accountNames),
        ];
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

        $this->displayPrefixSection();

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
     * Show the configured public ID prefixes — which are part of the LOCKED
     * format — with a sample generated id for each, so the user can change them
     * before the lock (and before any dev-data ids are generated under them).
     */
    private function displayPrefixSection(): void
    {
        $models = [
            'users' => (string) config('jamesgifford.auth.models.user'),
            'accounts' => (string) config('jamesgifford.auth.models.account'),
        ];

        $this->line('Public ID prefixes are part of that locked format — once IDs exist they');
        $this->line('cannot change. The configured prefixes (edit the <info>prefixes</info> map in');
        $this->line("config/jamesgifford/auth.php, or each model's publicIdPrefix()) are:");
        $this->newLine();

        foreach ($models as $label => $modelClass) {
            $sample = $this->prefixSample($modelClass);
            if ($sample === null) {
                $this->line(sprintf("        %-9s (no prefix configured — add it to the 'prefixes' map)", $label));

                continue;
            }

            [$prefix, $id] = $sample;
            $this->line(sprintf('        %-9s %-9s e.g. %s', $label, $prefix, $id));
        }

        $this->newLine();
    }

    /**
     * Resolve a model's effective prefix and a sample id, or null if the prefix
     * can't be resolved (e.g. the model isn't registered yet) — the pause must
     * never fail setup over a display detail.
     *
     * @return array{0: string, 1: string}|null
     */
    private function prefixSample(string $modelClass): ?array
    {
        if ($modelClass === '' || ! class_exists($modelClass)) {
            return null;
        }

        try {
            $prefix = app(PrefixRegistry::class)->prefixFor($modelClass);

            return [$prefix, PublicId::generate($prefix)];
        } catch (Throwable) {
            return null;
        }
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
