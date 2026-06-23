<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Exceptions;

use JamesGifford\Auth\Database\DevDataSeeder;
use RuntimeException;

/**
 * Thrown by {@see DevDataSeeder} when the current
 * environment is not permitted to seed dev data — either an unconditional
 * production refusal or a fails-closed allowlist miss. It is raised BEFORE any
 * database access so a refused run changes nothing.
 */
final class DevDataSeedingNotAllowedException extends RuntimeException
{
    public static function production(): self
    {
        return new self('Refusing to seed dev data in a production environment.');
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function environmentNotAllowed(string $environment, array $allowed): self
    {
        return new self(sprintf(
            "Refusing to seed dev data: environment '%s' is not in the allowed list [%s]. ".
            'Add it to config(\'jamesgifford.dev-data.environments\') to permit it.',
            $environment,
            $allowed === [] ? '(none)' : implode(', ', $allowed),
        ));
    }
}
