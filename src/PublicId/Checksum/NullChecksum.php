<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Checksum;

use Progravity\Auth\PublicId\Alphabet;

/**
 * No-op checksum strategy used when checksums are disabled in config
 * (e.g. `checksum.enabled = false`). Lets the rest of the system call
 * into a ChecksumStrategy uniformly without branching on whether
 * checksums are turned on.
 *
 * verify() always returns true regardless of inputs, so callers must
 * not rely on it for actual validation when checksums are disabled.
 */
final class NullChecksum implements ChecksumStrategy
{
    public function compute(string $body, Alphabet $alphabet, int $length): string
    {
        return '';
    }

    public function verify(string $body, string $checksum, Alphabet $alphabet, int $length): bool
    {
        return true;
    }
}
