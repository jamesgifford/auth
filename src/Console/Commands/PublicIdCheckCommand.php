<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Exceptions\PrefixCollisionException;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use Throwable;

/**
 * Verifies prefix registry integrity. Three checks:
 *  1. config-listed model classes actually autoload (typo detection)
 *  2. no two registered models claim the same prefix
 *  3. each registered prefix is well-formed
 *
 * Exits non-zero on any failure, making it suitable as a CI step.
 *
 * Run with: `php artisan jamesgifford:public-id:check`
 */
final class PublicIdCheckCommand extends Command
{
    protected $signature = 'jamesgifford:public-id:check';

    protected $description = 'Verify public_id prefix registry integrity and detect config issues.';

    public function __construct(
        private readonly PublicIdConfig $config,
        private readonly PrefixRegistry $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Public ID Configuration Check');
        $this->newLine();

        $issues = 0;

        // Check 1: Config-registered classes that don't autoload
        $missing = [];
        foreach ($this->config->prefixes() as $modelClass => $prefix) {
            if (! class_exists($modelClass)) {
                $missing[$modelClass] = $prefix;
            }
        }

        if ($missing === []) {
            $this->line('  ✓ Class autoload check passed.');
        } else {
            $this->line('  ✗ Class autoload check failed:');
            foreach ($missing as $modelClass => $prefix) {
                $this->line(sprintf("      %s (configured prefix: '%s') — class does not exist.", $modelClass, $prefix));
            }
            $this->line('      Possible typo in config/jamesgifford/auth.php under public_id.prefixes.');
            $issues++;
        }

        // Check 2: Prefix collisions
        try {
            $this->registry->assertNoCollisions();
            $this->line('  ✓ Prefix collision check passed.');
        } catch (PrefixCollisionException $e) {
            $this->line('  ✗ Prefix collision check failed:');
            $this->line('      '.$e->getMessage());
            $issues++;
        }

        // Check 3: Each registered model's prefix is well-formed
        $formatIssues = [];
        foreach (array_keys($this->registry->all()) as $modelClass) {
            try {
                $this->registry->prefixFor($modelClass);
            } catch (Throwable $e) {
                $formatIssues[$modelClass] = $e->getMessage();
            }
        }

        if ($formatIssues === []) {
            $this->line('  ✓ Prefix format check passed.');
        } else {
            $this->line('  ✗ Prefix format check failed:');
            foreach ($formatIssues as $modelClass => $detail) {
                $this->line(sprintf('      %s — %s', $modelClass, $detail));
            }
            $issues++;
        }

        $this->newLine();
        $this->displayRegistered();
        $this->newLine();

        if ($issues === 0) {
            $this->info('All checks passed.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d %s found.', $issues, $issues === 1 ? 'issue' : 'issues'));

        return self::FAILURE;
    }

    private function displayRegistered(): void
    {
        $registered = $this->registry->all();
        $count = count($registered);

        if ($count === 0) {
            $this->line('No prefixes registered.');

            return;
        }

        $this->line(sprintf('%d registered %s:', $count, $count === 1 ? 'prefix' : 'prefixes'));
        ksort($registered);
        $maxLen = max(array_map('strlen', array_keys($registered)));
        foreach ($registered as $modelClass => $prefix) {
            $this->line(sprintf('  %-'.$maxLen.'s  %s', $modelClass, $prefix));
        }
    }
}
