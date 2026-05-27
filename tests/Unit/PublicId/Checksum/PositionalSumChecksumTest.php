<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Unit\PublicId\Checksum;

use OutOfBoundsException;
use Progravity\Auth\PublicId\Alphabet;
use Progravity\Auth\PublicId\Checksum\PositionalSumChecksum;
use Progravity\Auth\Tests\TestCase;

class PositionalSumChecksumTest extends TestCase
{
    public function test_compute_returns_empty_string_when_length_is_zero(): void
    {
        $checksum = new PositionalSumChecksum;

        $this->assertSame('', $checksum->compute('anybody', $this->alphanumeric(), 0));
        $this->assertSame('', $checksum->compute('', $this->alphanumeric(), 0));
    }

    public function test_compute_returns_string_of_exact_length(): void
    {
        $checksum = new PositionalSumChecksum;
        $alphabet = $this->alphanumeric();

        $this->assertSame(2, strlen($checksum->compute('abcdef', $alphabet, 2)));
        $this->assertSame(5, strlen($checksum->compute('abcdef', $alphabet, 5)));
        $this->assertSame(8, strlen($checksum->compute('abcdef', $alphabet, 8)));
    }

    public function test_compute_returns_only_alphabet_characters(): void
    {
        $checksum = new PositionalSumChecksum;
        $alphabet = $this->alphanumeric();

        $result = $checksum->compute('zzzzzzzzzz9999999999', $alphabet, 4);

        foreach (mb_str_split($result) as $char) {
            $this->assertTrue(
                $alphabet->contains($char),
                "Character '{$char}' is not in the alphabet."
            );
        }
    }

    public function test_compute_is_deterministic(): void
    {
        $checksum = new PositionalSumChecksum;
        $alphabet = $this->alphanumeric();

        $first = $checksum->compute('hello', $alphabet, 2);
        $second = $checksum->compute('hello', $alphabet, 2);

        $this->assertSame($first, $second);
    }

    public function test_compute_produces_different_checksums_for_different_bodies(): void
    {
        $checksum = new PositionalSumChecksum;
        $alphabet = $this->alphanumeric();

        $this->assertNotSame(
            $checksum->compute('abc', $alphabet, 2),
            $checksum->compute('abd', $alphabet, 2),
        );
    }

    /**
     * Body "a" with alphabet "abcdefghijklmnopqrstuvwxyz0123456789" (a is at index 0):
     *   sum = 0 * 1 = 0
     *   modulus = 36^2 = 1296
     *   0 mod 1296 = 0
     *   toBase(0, alphabet, 2) = "aa" (zero pads to charAt(0) repeated)
     */
    public function test_compute_known_value_single_char_body(): void
    {
        $checksum = new PositionalSumChecksum;

        $this->assertSame('aa', $checksum->compute('a', $this->alphanumeric(), 2));
    }

    /**
     * Body "hello" with alphabet "abcdefghijklmnopqrstuvwxyz0123456789":
     *   h=7  *1 = 7
     *   e=4  *2 = 8
     *   l=11 *3 = 33
     *   l=11 *4 = 44
     *   o=14 *5 = 70
     *   sum = 162
     *   162 mod 1296 = 162
     *   162 = 4*36 + 18 → charAt(4)+charAt(18) = "e" + "s" = "es"
     */
    public function test_compute_known_value_word_body(): void
    {
        $checksum = new PositionalSumChecksum;

        $this->assertSame('es', $checksum->compute('hello', $this->alphanumeric(), 2));
    }

    public function test_verify_returns_true_for_matching_checksum(): void
    {
        $strategy = new PositionalSumChecksum;
        $alphabet = $this->alphanumeric();

        $produced = $strategy->compute('abc123', $alphabet, 2);

        $this->assertTrue($strategy->verify('abc123', $produced, $alphabet, 2));
    }

    public function test_verify_returns_false_for_wrong_checksum(): void
    {
        $strategy = new PositionalSumChecksum;
        $alphabet = $this->alphanumeric();

        $produced = $strategy->compute('abc123', $alphabet, 2);
        $wrong = $produced === 'aa' ? 'ab' : 'aa';

        $this->assertFalse($strategy->verify('abc123', $wrong, $alphabet, 2));
    }

    public function test_verify_returns_false_for_wrong_length_checksum(): void
    {
        $strategy = new PositionalSumChecksum;
        $alphabet = $this->alphanumeric();

        $this->assertFalse($strategy->verify('abc123', 'aaa', $alphabet, 2));
    }

    public function test_compute_throws_when_body_contains_char_outside_alphabet(): void
    {
        $strategy = new PositionalSumChecksum;
        $alphabet = new Alphabet('abc');

        $this->expectException(OutOfBoundsException::class);
        $strategy->compute('abz', $alphabet, 2);
    }

    private function alphanumeric(): Alphabet
    {
        return new Alphabet('abcdefghijklmnopqrstuvwxyz0123456789');
    }
}
