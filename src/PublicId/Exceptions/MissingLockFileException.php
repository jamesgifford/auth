<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Exceptions;

use JamesGifford\Auth\PublicId\Config\LockFile;
use RuntimeException;

/**
 * Thrown by {@see LockFile::read()} when no
 * lock file exists at the configured path.
 */
class MissingLockFileException extends RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(
            "No lock file found at '{$path}'. Run `php artisan jamesgifford:public-id:setup` to create one."
        );
    }
}
