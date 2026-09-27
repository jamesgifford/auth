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
 *    matching row — use {@see notSeeded()}. The package's roles migration
 *    inserts every configured role on migrate, so this now mostly means an
 *    app installed before that migration shipped hasn't published it yet
 *    (re-running `jamesgifford:auth:install` does), or the row was deleted.
 *    {@see AccountRoleSeeder} restores it too.
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
            'but has no matching row in the account_roles table. The package\'s roles '.
            'migration inserts it on migrate: if database/migrations has no '.
            '*_insert_jamesgifford_auth_account_roles migration (apps installed before it '.
            'shipped), run php artisan jamesgifford:auth:install to publish it, then migrate. '.
            'Running JamesGifford\\Auth\\Database\\Seeders\\AccountRoleSeeder also restores it '.
            '(php artisan db:seed --class="JamesGifford\\Auth\\Database\\Seeders\\AccountRoleSeeder").'
        );
    }
}
