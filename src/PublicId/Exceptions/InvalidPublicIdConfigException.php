<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Exceptions;

use RuntimeException;

class InvalidPublicIdConfigException extends RuntimeException
{
    public static function forKey(string $key, string $reason): self
    {
        return new self("Invalid public_id config: '{$key}' — {$reason}");
    }
}
