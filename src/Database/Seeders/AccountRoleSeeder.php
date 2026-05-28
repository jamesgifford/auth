<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use JamesGifford\Auth\Models\AccountRole;

/**
 * Seeds the account_roles table from config('jamesgifford.auth.roles').
 *
 * The config is the source of truth; this seeder is the mechanism that
 * brings the database into line with it. Idempotent via updateOrCreate keyed
 * on the role's `key`, so re-running picks up consumer-added roles and
 * updates renamed/re-described system roles without duplicating rows.
 *
 * It deliberately does NOT delete roles that exist in the database but no
 * longer appear in config. Cleaning up such orphans is the consumer's choice.
 */
class AccountRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = config('jamesgifford.auth.roles', []);

        foreach ($roles as $key => $attributes) {
            AccountRole::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $attributes['name'],
                    'description' => $attributes['description'] ?? null,
                    'system' => $attributes['system'] ?? false,
                    'sort_order' => $attributes['sort_order'] ?? 0,
                ]
            );
        }
    }
}
