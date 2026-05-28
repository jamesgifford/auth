<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Exceptions;

use JamesGifford\Auth\PublicId\PrefixRegistry;
use RuntimeException;

/**
 * Thrown when two or more registered models claim the same public_id prefix.
 * Detected at boot via {@see PrefixRegistry::assertNoCollisions()}.
 */
class PrefixCollisionException extends RuntimeException
{
    /**
     * @param  array<int, string>  $modelClasses
     */
    public static function forPrefix(string $prefix, array $modelClasses): self
    {
        return new self(sprintf(
            "Prefix '%s' is claimed by multiple models: %s. Each public_id prefix must be unique. ".
            'Resolve by changing the prefix on one of these models, either by updating '.
            'publicIdPrefix() or the config/jamesgifford/auth.php prefixes map.',
            $prefix,
            implode(', ', $modelClasses),
        ));
    }
}
