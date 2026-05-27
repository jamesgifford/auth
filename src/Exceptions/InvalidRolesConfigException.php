<?php

declare(strict_types=1);

namespace Progravity\Auth\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when the `progravity.auth.roles` config array fails validation.
 * Use {@see forKey()} so the message names the offending role key and reason.
 */
class InvalidRolesConfigException extends InvalidArgumentException
{
    public static function forKey(string $key, string $reason): self
    {
        return new self("Invalid roles config: '{$key}' — {$reason}");
    }

    public static function general(string $reason): self
    {
        return new self("Invalid roles config: {$reason}");
    }
}
