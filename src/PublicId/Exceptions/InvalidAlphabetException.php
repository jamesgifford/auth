<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Exceptions;

use InvalidArgumentException;
use JamesGifford\Auth\PublicId\Alphabet;

/**
 * Thrown when an alphabet string fails {@see Alphabet}
 * validation: too short, or contains duplicate characters.
 */
class InvalidAlphabetException extends InvalidArgumentException
{
    /**
     * @param  array<int, string>  $duplicateChars
     */
    public static function forDuplicates(string $alphabet, array $duplicateChars): self
    {
        return new self(sprintf(
            "Alphabet '%s' contains duplicate characters: %s. Each character in an alphabet must be unique.",
            $alphabet,
            implode(', ', $duplicateChars),
        ));
    }

    public static function forTooShort(string $alphabet): self
    {
        return new self(
            "Alphabet '{$alphabet}' is too short. Alphabets must contain at least 2 characters."
        );
    }
}
