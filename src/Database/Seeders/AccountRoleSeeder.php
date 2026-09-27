<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use JamesGifford\Auth\PackageModels;
use JamesGifford\Auth\Roles\AccountRoleProvisioner;
use JamesGifford\Auth\Roles\RolesConfig;

/**
 * Seeds the account_roles table from config('jamesgifford.auth.roles').
 *
 * The config is the source of truth; this seeder is the mechanism that
 * brings the database into line with it. Idempotent via updateOrCreate keyed
 * on the role's `key`, so re-running picks up consumer-added roles and
 * updates renamed/re-described system roles without duplicating rows.
 *
 * The package's roles migration already inserts any missing roles on every
 * migrate, so this seeder is no longer required for account creation; it
 * remains the way to push later config edits (renames, new descriptions) onto
 * rows that already exist. Both read the same definitions
 * ({@see RolesConfig::current()}) and build rows the same way
 * ({@see AccountRoleProvisioner::attributes()}).
 *
 * It deliberately does NOT delete roles that exist in the database but no
 * longer appear in config. Cleaning up such orphans is the consumer's choice.
 */
class AccountRoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RolesConfig::current()->roles() as $key => $definition) {
            PackageModels::accountRole()::query()->updateOrCreate(
                ['key' => $key],
                AccountRoleProvisioner::attributes($definition),
            );
        }
    }
}
