<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Auth\Database\DevDataSeeder;
use JamesGifford\Auth\Exceptions\DevDataSeedingNotAllowedException;

/**
 * Seed deterministic local dev fixtures from config('jamesgifford.auth-dev').
 *
 * Dev/local only: refuses in production (unconditionally) and in any environment
 * not allow-listed in config — the guard runs before any database access.
 *
 * Intended ordering (the package does NOT orchestrate this — wire it into your
 * own DatabaseSeeder or a local setup command):
 *
 *     migrate:fresh → (seed roles) → seed-dev-data → apply-id-offsets
 *
 * This command deliberately does NOT call apply-id-offsets; run that separately
 * afterwards so real records start above the seeded dev ids.
 */
final class AuthSeedDevDataCommand extends Command
{
    protected $signature = 'jamesgifford:auth:seed-dev-data';

    protected $description = 'Seed deterministic local dev users/accounts from config (dev/local only; refuses in production). Run apply-id-offsets afterwards.';

    public function __construct(private readonly DevDataSeeder $seeder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Seeding dev data...');
        $this->newLine();

        // Guard FIRST so a refused (e.g. production) run writes nothing — no
        // published file, no database changes.
        try {
            $this->seeder->assertEnvironmentAllowed();
        } catch (DevDataSeedingNotAllowedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Publish the dev-data config so the consumer has a file to customize,
        // unless they already have one (then it's left untouched).
        $this->ensureDevDataConfigPublished();

        // seed() re-asserts the guard (cheap, no side effects) before any query.
        $counts = $this->seeder->seed();

        $this->line(sprintf(
            '  Seeded %d user(s), %d account(s), %d membership(s).',
            $counts['users'],
            $counts['accounts'],
            $counts['memberships'],
        ));

        $this->displayPasswordWarnings();

        $this->newLine();
        $this->line('Next: run `php artisan jamesgifford:auth:apply-id-offsets` so real records');
        $this->line('start above these dev ids. (This command does not run it for you.)');

        return self::SUCCESS;
    }

    /**
     * Post-seed password warnings. Printed AFTER seeding because they describe
     * what was actually seeded — and here, in the command itself, so they reach
     * every path that seeds: direct runs, `setup --with-dev-data`, and
     * non-interactive `setup --force` runs that never see the educational pause.
     */
    private function displayPasswordWarnings(): void
    {
        if ($this->seeder->legacyPasswordKeyPresent()) {
            $this->newLine();
            $this->warn("  The dev config contains the legacy 'password' key, which is ignored.");
            $this->line("  Rename it to 'users_password' in config/jamesgifford/auth-dev.php — the");
            $this->line('  package default reads the JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD env var.');
        }

        if ($this->seeder->resolveUsersPassword() === 'password') {
            $this->newLine();
            $this->warn('  Dev users were seeded with the default password "password".');
            $this->line('  Set JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD in your .env and re-run this');
            $this->line('  command to update them (the value is hashed at seed time).');
        }
    }

    /**
     * Publish config/jamesgifford/auth-dev.php on first run so the consumer has
     * an editable cast. Never overwrites an existing file (vendor:publish skips
     * it, and we only announce a publish that actually happened).
     */
    private function ensureDevDataConfigPublished(): void
    {
        $target = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth-dev.php');

        if (is_file($target)) {
            return;
        }

        $this->callSilent('vendor:publish', ['--tag' => 'jamesgifford-auth-dev']);

        if (is_file($target)) {
            $this->line('  Published dev-data config to '.$this->displayPath($target).' — edit it to customize the cast.');
            $this->newLine();
        }
    }

    private function displayPath(string $path): string
    {
        $base = $this->laravel->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
