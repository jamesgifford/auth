<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use JamesGifford\Auth\Database\IdOffsetManager;
use Throwable;

/**
 * Re-applies the configured auto-increment offsets as part of a seeding run.
 *
 * `migrate:fresh --seed` and `migrate:refresh --seed` reset each table's
 * auto-increment counter, so offsets applied at setup time are lost on every
 * rebuild. Wiring this seeder after the data seeders restores them, which is
 * what lets a developer rebuild the database without re-running
 * `jamesgifford:auth:apply-id-offsets` by hand.
 *
 * Ordering matters: it must run AFTER the fixtures it reserves room above.
 *
 * Safe unattended in any environment — {@see IdOffsetManager::apply()} skips a
 * table whose ids already reach the offset, skips unsupported drivers
 * (SQLite), and no-ops when nothing is configured.
 */
final class ApplyIdOffsetsSeeder extends Seeder
{
    public function __construct(private readonly IdOffsetManager $offsets) {}

    public function run(): void
    {
        try {
            $this->offsets->apply();
        } catch (Throwable $e) {
            // Offsets are a convenience, not a correctness requirement, so no
            // failure here may abort the consuming application's entire seeding
            // run — not a malformed offset (a config typo, InvalidArgumentException)
            // and not a driver-level refusal of the ALTER (insufficient grants, a
            // locked table), which would otherwise surface as a QueryException and
            // take the whole `--seed` down with it. Install catches Throwable at
            // its own offset step for the same reason
            // (AuthInstallCommand::maybeApplyIdOffsets).
            Log::warning('[jamesgifford/auth] Skipping ID offsets: '.$e->getMessage());
        }
    }
}
