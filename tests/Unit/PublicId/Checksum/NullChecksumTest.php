<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\PublicId\Checksum;

use JamesGifford\Auth\PublicId\Alphabet;
use JamesGifford\Auth\PublicId\Checksum\NullChecksum;
use JamesGifford\Auth\Tests\TestCase;

class NullChecksumTest extends TestCase
{
    public function test_compute_returns_empty_string_for_length_zero(): void
    {
        $checksum = new NullChecksum;

        $this->assertSame('', $checksum->compute('anything', $this->alphabet(), 0));
    }

    public function test_verify_returns_true_regardless_of_inputs(): void
    {
        $checksum = new NullChecksum;

        $this->assertTrue($checksum->verify('', 'whatever', $this->alphabet(), 99));
    }

    private function alphabet(): Alphabet
    {
        return new Alphabet('abcdefghijklmnopqrstuvwxyz0123456789');
    }
}
