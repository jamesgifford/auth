<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Exceptions;

use JamesGifford\Auth\PublicId\Config\LockFile;
use RuntimeException;
use Throwable;

/**
 * Thrown by {@see LockFile::write()} and
 * `delete()` on directory-creation, JSON-encoding, or file-system failures.
 */
class LockFileWriteException extends RuntimeException
{
    public static function forPath(string $path, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            "Failed to write lock file at '{$path}': {$reason}. ".
            'Verify the directory exists and is writable.',
            0,
            $previous,
        );
    }
}
