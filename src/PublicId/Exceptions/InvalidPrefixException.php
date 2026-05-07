<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Exceptions;

use InvalidArgumentException;

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
}
