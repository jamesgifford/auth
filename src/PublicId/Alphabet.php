<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId;

use InvalidArgumentException;
use OutOfBoundsException;
use Progravity\Auth\PublicId\Exceptions\InvalidAlphabetException;

/**
 * Immutable value object representing a resolved alphabet — the set of
 * characters available for body and checksum generation.
 *
 * Validated on construction: at least 2 characters, no duplicates.
 */
final class Alphabet
{
    private readonly string $characters;

    private readonly int $size;

    /**
     * @var array<string, int>
     */
    private readonly array $index;

    /**
     * @throws InvalidAlphabetException when the input is shorter than 2
     *                                  characters or contains duplicates
     */
    public function __construct(string $characters)
    {
        $chars = mb_str_split($characters);
        $size = count($chars);

        if ($size < 2) {
            throw InvalidAlphabetException::forTooShort($characters);
        }

        $map = [];
        $duplicates = [];
        foreach ($chars as $position => $char) {
            if (array_key_exists($char, $map)) {
                $duplicates[$char] = true;

                continue;
            }
            $map[$char] = $position;
        }

        if ($duplicates !== []) {
            throw InvalidAlphabetException::forDuplicates(
                $characters,
                array_keys($duplicates),
            );
        }

        $this->characters = $characters;
        $this->size = $size;
        $this->index = $map;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * @throws InvalidArgumentException when $char is not exactly one character
     */
    public function contains(string $char): bool
    {
        $this->assertSingleCharacter($char);

        return array_key_exists($char, $this->index);
    }

    /**
     * @throws InvalidArgumentException when $char is not exactly one character
     * @throws OutOfBoundsException when $char is not a member of the alphabet
     */
    public function indexOf(string $char): int
    {
        $this->assertSingleCharacter($char);

        if (! array_key_exists($char, $this->index)) {
            throw new OutOfBoundsException(
                "Character '{$char}' is not in the alphabet."
            );
        }

        return $this->index[$char];
    }

    /**
     * @throws OutOfBoundsException when $index is outside [0, size)
     */
    public function charAt(int $index): string
    {
        if ($index < 0 || $index >= $this->size) {
            throw new OutOfBoundsException(
                "Index {$index} is out of range [0, {$this->size})."
            );
        }

        return mb_substr($this->characters, $index, 1);
    }

    public function toString(): string
    {
        return $this->characters;
    }

    public function equals(Alphabet $other): bool
    {
        return $this->characters === $other->characters;
    }

    private function assertSingleCharacter(string $char): void
    {
        if (mb_strlen($char) !== 1) {
            throw new InvalidArgumentException(
                'Expected a single character, got a string of length '.mb_strlen($char).'.'
            );
        }
    }
}
