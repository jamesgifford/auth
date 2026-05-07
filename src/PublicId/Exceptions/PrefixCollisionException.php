<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Exceptions;

use RuntimeException;

class PrefixCollisionException extends RuntimeException
{
    /**
     * @param  array<int, string>  $modelClasses
     */
    public static function forPrefix(string $prefix, array $modelClasses): self
    {
        return new self(sprintf(
            "Prefix '%s' is claimed by multiple models: %s. Each public_id prefix must be unique.",
            $prefix,
            implode(', ', $modelClasses),
        ));
    }
}
