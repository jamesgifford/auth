<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Config;

final class LockFileContents
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly int $version,
        public readonly string $lockedAt,
        public readonly string $fingerprint,
        public readonly array $config,
    ) {
    }
}
