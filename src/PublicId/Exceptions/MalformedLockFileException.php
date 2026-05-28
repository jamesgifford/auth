<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Exceptions;

use JamesGifford\Auth\PublicId\Config\LockFile;
use RuntimeException;

/**
 * Thrown by {@see LockFile::read()} when the
 * lock file content is unreadable or not valid JSON.
 */
class MalformedLockFileException extends RuntimeException
{
    public static function forPath(string $path, string $jsonError): self
    {
        return new self(
            "Lock file at '{$path}' contains malformed JSON: {$jsonError}. ".
            'Either restore the file from version control or run '.
            '`php artisan jamesgifford:public-id:reset --i-understand-this-breaks-existing-ids` '.
            'to remove it.'
        );
    }
}
