<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Config;

/**
 * Three states the {@see ConfigGuard} can report:
 *  - NotYetLocked: no lock file exists; setup hasn't been run
 *  - Locked: lock file exists and matches the current configuration
 *  - Drifted: lock file exists but its fingerprint no longer matches
 */
enum GuardStatus
{
    case NotYetLocked;
    case Locked;
    case Drifted;
}
