<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\PublicId;

use InvalidArgumentException;
use JamesGifford\Auth\PublicId\Alphabet;
use JamesGifford\Auth\PublicId\Exceptions\InvalidAlphabetException;
use JamesGifford\Auth\Tests\TestCase;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;

class AlphabetTest extends TestCase
{
    public function test_constructs_with_two_characters(): void
    {
        $alphabet = new Alphabet('ab');

        $this->assertSame(2, $alphabet->size());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideInvalidConstructorInputs(): array
    {
        return [
            'empty string' => ['', 'too short'],
            'single character' => ['a', 'too short'],
            'duplicate characters' => ['aab', 'duplicate'],
        ];
    }

    #[DataProvider('provideInvalidConstructorInputs')]
    public function test_construct_rejects_invalid_input(string $characters, string $expectedMessage): void
    {
        $this->expectException(InvalidAlphabetException::class);
        $this->expectExceptionMessage($expectedMessage);

        new Alphabet($characters);
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
