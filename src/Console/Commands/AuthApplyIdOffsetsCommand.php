<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use JamesGifford\Auth\Database\IdOffsetManager;

/**
 * Apply the configured auto-increment ID offsets (config
 * 'jamesgifford.auth.id_offsets') to the users and accounts tables.
 *
 * Intended ordering: migrate → seed roles → (optionally seed dev data) →
 * apply-id-offsets. Run it LAST so the auto-increment counter lands above any
 * existing records — in production after migrating (no dev data), or locally
 * after dev-data seeding. It is deliberately NOT coupled to any dev-data
 * feature; it just needs to run as a discrete final step.
 *
 * Safe to run anytime: a no-op for tables with null offsets or on unsupported
 * drivers (SQLite). Offsets are legitimate in production, so there is no
 * environment restriction.
 */
final class AuthApplyIdOffsetsCommand extends Command
{
    protected $signature = 'jamesgifford:auth:apply-id-offsets';

    protected $description = 'Apply configured auto-increment ID offsets to the users and accounts tables (run after migrate and any seeding).';

    public function __construct(private readonly IdOffsetManager $offsets)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Applying configured ID offsets...');
        $this->newLine();

        try {
            $results = $this->offsets->apply();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $result) {
            if ($result['applied']) {
                $this->line(sprintf(
                    '  ✓ %s: auto-increment set to %d (%s)',
                    $result['table'],
                    $result['offset'],
                    $result['driver'],
                ));
            } else {
                $this->line(sprintf('  - %s: skipped — %s', $result['table'], $result['reason']));
            }
        }

        $applied = count(array_filter($results, static fn (array $r): bool => $r['applied']));

        $this->newLine();
        $this->line($applied === 0
            ? 'No ID offsets applied (nothing to do).'
            : sprintf('Applied ID offsets to %d table(s).', $applied));

        // Null offsets / unsupported drivers are valid states — exit success.
        return self::SUCCESS;
    }
}
