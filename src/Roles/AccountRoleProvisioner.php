<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Roles;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Models\AccountRole;

/**
 * Inserts and removes the configured account roles for the package's roles
 * migration, so a freshly migrated database has every role account creation
 * needs without a seeder running.
 *
 * Role definitions come from {@see RolesConfig::current()}, the same source
 * {@see AccountRoleSeeder} uses, and both write rows through
 * {@see attributes()}, so the two can never disagree about what a role row
 * looks like.
 *
 * Works on the table directly rather than through {@see AccountRole}: a
 * migration must not depend on a model class (a published subclass may add
 * casts, columns, or events that only exist later in the migration history),
 * and AccountRole's guard against deleting system roles protects application
 * code, not the rollback of the migration that inserted them.
 */
final class AccountRoleProvisioner
{
    private const TABLE = 'account_roles';

    /**
     * Insert every configured role whose key has no row yet. Existing rows are
     * left exactly as they are — including ones an app seeded or renamed
     * itself — so this is safe to run against any database, repeatedly.
     *
     * @return list<string> the keys inserted
     */
    public function insertMissing(): array
    {
        $definitions = RolesConfig::current()->roles();

        $existing = DB::table(self::TABLE)
            ->whereIn('key', array_keys($definitions))
            ->pluck('key')
            ->all();

        $now = now();
        $rows = [];

        foreach ($definitions as $key => $definition) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            $rows[] = ['key' => $key, ...self::attributes($definition), 'created_at' => $now, 'updated_at' => $now];
        }

        if ($rows !== []) {
            DB::table(self::TABLE)->insert($rows);
        }

        return array_column($rows, 'key');
    }

    /**
     * Delete the configured roles, except any a membership still references:
     * account_role_id is restrictOnDelete, and a rollback must not abort over
     * data that is still in use. A no-op once the table is gone, so the
     * uninstaller can run it against a partially torn-down schema.
     *
     * @return list<string> the keys removed
     */
    public function removeUnused(): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $unused = DB::table(self::TABLE)->whereIn('key', array_keys(RolesConfig::current()->roles()));

        if (Schema::hasTable('account_user')) {
            $unused->whereNotExists(fn (Builder $memberships) => $memberships
                ->select(DB::raw(1))
                ->from('account_user')
                ->whereColumn('account_user.account_role_id', self::TABLE.'.id'));
        }

        /** @var list<string> $keys */
        $keys = (clone $unused)->pluck('key')->all();
        $unused->delete();

        return $keys;
    }

    /**
     * The account_roles column values for one role definition.
     *
     * @param  array<string, mixed>  $definition  one entry of config('jamesgifford.auth.roles')
     * @return array{name: mixed, description: mixed, system: mixed, sort_order: mixed}
     */
    public static function attributes(array $definition): array
    {
        return [
            'name' => $definition['name'],
            'description' => $definition['description'] ?? null,
            'system' => $definition['system'] ?? false,
            'sort_order' => $definition['sort_order'] ?? 0,
        ];
    }
}
