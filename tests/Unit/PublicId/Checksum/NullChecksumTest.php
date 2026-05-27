<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Unit\PublicId\Checksum;

use Progravity\Auth\PublicId\Alphabet;
use Progravity\Auth\PublicId\Checksum\NullChecksum;
use Progravity\Auth\Tests\TestCase;

class NullChecksumTest extends TestCase
{
    public function test_compute_returns_empty_string_for_length_zero(): void
    {
        $checksum = new NullChecksum;

        $this->assertSame('', $checksum->compute('anything', $this->alphabet(), 0));
    }

    public function test_compute_returns_empty_string_for_length_two(): void
    {
        $checksum = new NullChecksum;

        $this->assertSame('', $checksum->compute('anything', $this->alphabet(), 2));
    }

    public function test_compute_returns_empty_string_for_length_five(): void
    {
        $checksum = new NullChecksum;

        $this->assertSame('', $checksum->compute('anything', $this->alphabet(), 5));
    }

    public function test_compute_returns_empty_string_for_empty_body(): void
    {
        $checksum = new NullChecksum;

        $this->assertSame('', $checksum->compute('', $this->alphabet(), 5));
    }

    public function test_verify_returns_true_for_matching_checksum(): void
    {
        $checksum = new NullChecksum;

        $this->assertTrue($checksum->verify('body', '', $this->alphabet(), 0));
    }

    public function test_verify_returns_true_for_non_matching_checksum(): void
    {
        $checksum = new NullChecksum;

        $this->assertTrue($checksum->verify('body', 'xx', $this->alphabet(), 2));
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
