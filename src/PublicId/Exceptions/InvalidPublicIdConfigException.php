<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when the `jamesgifford.auth.public_id` config array fails validation.
 * Use {@see forKey()} so the message names the offending key and reason.
 */
class InvalidPublicIdConfigException extends InvalidArgumentException
{
    public static function forKey(string $key, string $reason): self
    {
        return new self("Invalid public_id config: '{$key}' — {$reason}");
    }
}
