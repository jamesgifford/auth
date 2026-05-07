<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Unit\PublicId;

use InvalidArgumentException;
use OutOfBoundsException;
use Progravity\Auth\PublicId\Alphabet;
use Progravity\Auth\PublicId\Exceptions\InvalidAlphabetException;
use Progravity\Auth\Tests\TestCase;

class AlphabetTest extends TestCase
{
    public function test_constructs_with_two_characters(): void
    {
        $alphabet = new Alphabet('ab');

        $this->assertSame(2, $alphabet->size());
    }

    public function test_constructs_with_lowercase_alpha_size_26(): void
    {
        $alphabet = new Alphabet('abcdefghijklmnopqrstuvwxyz');

        $this->assertSame(26, $alphabet->size());
    }

    public function test_constructs_with_lowercase_alphanumeric_size_36(): void
    {
        $alphabet = new Alphabet('abcdefghijklmnopqrstuvwxyz0123456789');

        $this->assertSame(36, $alphabet->size());
    }

    public function test_constructs_with_mixed_alphanumeric_size_62(): void
    {
        $alphabet = new Alphabet(
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
        );

        $this->assertSame(62, $alphabet->size());
    }

    public function test_construct_rejects_empty_string(): void
    {
        $this->expectException(InvalidAlphabetException::class);

        new Alphabet('');
    }

    public function test_construct_rejects_single_character(): void
    {
        $this->expectException(InvalidAlphabetException::class);

        new Alphabet('a');
    }

    public function test_construct_rejects_duplicate_characters(): void
    {
        $this->expectException(InvalidAlphabetException::class);

        new Alphabet('aab');
    }

    public function test_contains_returns_true_for_present_chars(): void
    {
        $alphabet = new Alphabet('abc');

        $this->assertTrue($alphabet->contains('a'));
        $this->assertTrue($alphabet->contains('b'));
        $this->assertTrue($alphabet->contains('c'));
    }

    public function test_contains_returns_false_for_absent_chars(): void
    {
        $alphabet = new Alphabet('abc');

        $this->assertFalse($alphabet->contains('z'));
    }

    public function test_contains_throws_on_empty_string(): void
    {
        $alphabet = new Alphabet('abc');

        $this->expectException(InvalidArgumentException::class);
        $alphabet->contains('');
    }

    public function test_contains_throws_on_multi_character_input(): void
    {
        $alphabet = new Alphabet('abc');

        $this->expectException(InvalidArgumentException::class);
        $alphabet->contains('ab');
    }

    public function test_index_of_returns_zero_based_index(): void
    {
        $alphabet = new Alphabet('abcdef');

        $this->assertSame(0, $alphabet->indexOf('a'));
        $this->assertSame(3, $alphabet->indexOf('d'));
        $this->assertSame(5, $alphabet->indexOf('f'));
    }

    public function test_index_of_throws_for_absent_character(): void
    {
        $alphabet = new Alphabet('abc');

        $this->expectException(OutOfBoundsException::class);
        $alphabet->indexOf('z');
    }

    public function test_char_at_returns_character_for_valid_index(): void
    {
        $alphabet = new Alphabet('abcdef');

        $this->assertSame('a', $alphabet->charAt(0));
        $this->assertSame('c', $alphabet->charAt(2));
        $this->assertSame('f', $alphabet->charAt(5));
    }

    public function test_char_at_throws_on_negative_index(): void
    {
        $alphabet = new Alphabet('abc');

        $this->expectException(OutOfBoundsException::class);
        $alphabet->charAt(-1);
    }

    public function test_char_at_throws_on_index_at_size(): void
    {
        $alphabet = new Alphabet('abc');

        $this->expectException(OutOfBoundsException::class);
        $alphabet->charAt(3);
    }

    public function test_char_at_throws_on_index_above_size(): void
    {
        $alphabet = new Alphabet('abc');

        $this->expectException(OutOfBoundsException::class);
        $alphabet->charAt(99);
    }

    public function test_to_string_returns_original(): void
    {
        $alphabet = new Alphabet('xyz123');

        $this->assertSame('xyz123', $alphabet->toString());
    }

    public function test_magic_to_string_returns_original(): void
    {
        $alphabet = new Alphabet('xyz123');

        $this->assertSame('xyz123', (string) $alphabet);
    }

    public function test_equals_returns_true_for_same_characters(): void
    {
        $a = new Alphabet('abc');
        $b = new Alphabet('abc');

        $this->assertTrue($a->equals($b));
    }

    public function test_equals_returns_false_for_different_characters(): void
    {
        $a = new Alphabet('abc');
        $b = new Alphabet('abd');

        $this->assertFalse($a->equals($b));
    }

    public function test_equals_is_order_sensitive(): void
    {
        $a = new Alphabet('abc');
        $b = new Alphabet('cba');

        $this->assertFalse($a->equals($b));
    }
}
