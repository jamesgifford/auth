<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Progravity\Auth\PublicId\Config\LockFile::read()} when the
 * lock file parses as JSON but is missing required keys or has wrongly
 * typed values. The factory message lists every missing/typed key so the
 * caller doesn't need to fix them one at a time.
 */
class IncompleteLockFileException extends RuntimeException
{
    /**
     * @param  array<int, string>  $missingKeys
     */
    public static function forPath(string $path, array $missingKeys): self
    {
        return new self(sprintf(
            "Lock file at '%s' is missing required keys: %s. ".
            'The file may have been written by an incompatible version of the package '.
            'or manually edited. Restore from version control or run '.
            '`php artisan progravity:public-id:reset --i-understand-this-breaks-existing-ids`.',
            $path,
            implode(', ', $missingKeys),
        ));
    }
}
