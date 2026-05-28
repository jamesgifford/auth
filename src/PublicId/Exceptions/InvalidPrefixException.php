<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a prefix value isn't 1 to prefix_max_length lowercase ASCII
 * letters — either passed to {@see Generator::generate()} directly or
 * returned by a model's `publicIdPrefix()` method.
 */
class InvalidPrefixException extends InvalidArgumentException
{
    public static function forPrefix(string $prefix, int $maxLength): self
    {
        return new self(sprintf(
            "Invalid public_id prefix '%s': must be 1 to %d lowercase ASCII letters.",
            $prefix,
            $maxLength,
        ));
    }

    public static function forModelMethod(string $modelClass, mixed $invalidPrefix, int $maxLength): self
    {
        $rendered = is_string($invalidPrefix) ? "'{$invalidPrefix}'" : get_debug_type($invalidPrefix);

        return new self(sprintf(
            "Model '%s'::publicIdPrefix() returned invalid prefix %s: must be 1 to %d lowercase ASCII letters.",
            $modelClass,
            $rendered,
            $maxLength,
        ));
    }
}
