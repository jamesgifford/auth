<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use JamesGifford\Auth\Roles\AccountRoleProvisioner;

return new class extends Migration
{
    /**
     * Insert the configured account roles that have no row yet, so a freshly
     * migrated database (migrate:fresh, a RefreshDatabase test run, a first
     * deploy) can create accounts without a seeder ever running. The roles come
     * from config('jamesgifford.auth.roles') — the same definitions
     * AccountRoleSeeder uses. Existing rows are left untouched.
     */
    public function up(): void
    {
        (new AccountRoleProvisioner)->insertMissing();
    }

    /**
     * Remove the configured roles again, except any a membership still
     * references. A no-op once account_roles is gone.
     */
    public function down(): void
    {
        (new AccountRoleProvisioner)->removeUnused();
    }
};
