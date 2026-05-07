<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Checksum;

use Progravity\Auth\PublicId\Alphabet;

final class PositionalSumChecksum implements ChecksumStrategy
{
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
