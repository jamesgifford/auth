<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Auth\Database\DevDataSeeder;
use JamesGifford\Auth\Exceptions\DevDataSeedingNotAllowedException;

/**
 * Seed deterministic local dev fixtures from config('jamesgifford.dev-data').
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

        try {
            $counts = $this->seeder->seed();
        } catch (DevDataSeedingNotAllowedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  Seeded %d user(s), %d account(s), %d membership(s).',
            $counts['users'],
            $counts['accounts'],
            $counts['memberships'],
        ));

        $this->newLine();
        $this->line('Next: run `php artisan jamesgifford:auth:apply-id-offsets` so real records');
        $this->line('start above these dev ids. (This command does not run it for you.)');

        return self::SUCCESS;
    }
}
