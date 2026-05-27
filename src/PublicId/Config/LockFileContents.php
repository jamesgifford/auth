<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Config;

/**
 * Immutable parsed form of a lock file. Pure data carrier — produced by
 * {@see LockFile::read()}, never constructed by consumers directly.
 */
final class LockFileContents
{
    /**
     * @param  array<string, mixed>  $config  nested config snapshot from the lock file
     */
    public function __construct(
        public readonly int $version,
        public readonly string $lockedAt,
        public readonly string $fingerprint,
        public readonly array $config,
    ) {}
}
