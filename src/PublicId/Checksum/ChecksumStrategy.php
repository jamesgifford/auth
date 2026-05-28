<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Checksum;

use JamesGifford\Auth\PublicId\Alphabet;

/**
 * Computes and verifies checksums for public-ID bodies.
 *
 * Implementations must be:
 *  - Deterministic: the same body, alphabet, and length always produce the same checksum.
 *  - Pure: no side effects, no external state.
 *  - Length-respecting: compute() returns a string of exactly $length characters
 *    (or empty string when $length is 0), composed only of characters present in $alphabet.
 */
interface ChecksumStrategy
{
    public function compute(string $body, Alphabet $alphabet, int $length): string;

    public function verify(string $body, string $checksum, Alphabet $alphabet, int $length): bool;
}
