<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Checksum;

use JamesGifford\Auth\PublicId\Alphabet;
use OutOfBoundsException;

/**
 * Default checksum strategy. For each character in the body, multiplies
 * its zero-based alphabet index by its 1-indexed position in the body,
 * sums the products, takes the result modulo `alphabet_size ^ length`,
 * and renders that integer in the alphabet's base.
 *
 * Detects single-character substitutions and (with two-character
 * checksums) most adjacent transpositions.
 */
final class PositionalSumChecksum implements ChecksumStrategy
{
    /**
     * @throws OutOfBoundsException when `$body` contains a character
     *                              not present in `$alphabet`
     */
    public function compute(string $body, Alphabet $alphabet, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $chars = mb_str_split($body);
        $sum = 0;

        foreach ($chars as $position => $char) {
            $sum += $alphabet->indexOf($char) * ($position + 1);
        }

        $modulus = $alphabet->size() ** $length;
        $value = $sum % $modulus;

        return $this->toBase($value, $alphabet, $length);
    }

    public function verify(string $body, string $checksum, Alphabet $alphabet, int $length): bool
    {
        return hash_equals($this->compute($body, $alphabet, $length), $checksum);
    }

    private function toBase(int $value, Alphabet $alphabet, int $length): string
    {
        $base = $alphabet->size();
        $result = '';

        while ($value > 0) {
            $result = $alphabet->charAt($value % $base).$result;
            $value = intdiv($value, $base);
        }

        return str_pad($result, $length, $alphabet->charAt(0), STR_PAD_LEFT);
    }
}
