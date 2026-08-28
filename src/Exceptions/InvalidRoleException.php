<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Exceptions;

use InvalidArgumentException;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Models\AccountRole;

/**
 * Thrown when an operation references a role key that cannot be resolved
 * to a usable {@see AccountRole}. Two independent
 * causes share this exception:
 *
 *  - The key isn't declared in config('jamesgifford.auth.roles') at all —
 *    use {@see forKey()}.
 *  - The key IS declared in config, but the `account_roles` table has no
 *    matching row — use {@see notSeeded()}. This happens when
 *    {@see AccountRoleSeeder} hasn't
 *    run yet, most commonly in a consumer's test suite: Laravel's
 *    RefreshDatabase trait migrates the schema fresh but does not run
 *    seeders unless the test opts in (`$seed = true` or an explicit
 *    `$this->seed(...)` call).
 */
class InvalidRoleException extends InvalidArgumentException
{
    public static function forKey(string $roleKey): self
    {
        return new self(
            "No role exists with key '{$roleKey}'. ".
            "Available roles are configured in config('jamesgifford.auth.roles')."
        );
    }

    public static function notSeeded(string $roleKey): self
    {
        return new self(
            "Role '{$roleKey}' is configured in config('jamesgifford.auth.roles') ".
            'but has no matching row in the account_roles table. Run '.
            'JamesGifford\\Auth\\Database\\Seeders\\AccountRoleSeeder '.
            '(e.g. php artisan db:seed --class="JamesGifford\\Auth\\Database\\Seeders\\AccountRoleSeeder"). '.
            'If this is a test, RefreshDatabase alone does not run seeders — '.
            'seed explicitly or set $seed = true on your base TestCase.'
        );
    }
}
