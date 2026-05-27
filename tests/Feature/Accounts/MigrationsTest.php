<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Progravity\Auth\Models\Account;
use Progravity\Auth\Tests\Support\Fixtures\User;

class MigrationsTest extends AccountsTestCase
{
    public function test_users_table_gains_package_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'public_id'));
        $this->assertTrue(Schema::hasColumn('users', 'current_account_id'));
    }

    public function test_account_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertTrue(Schema::hasTable('account_roles'));
        $this->assertTrue(Schema::hasTable('account_user'));

        $this->assertTrue(Schema::hasColumns('accounts', [
            'id', 'public_id', 'name', 'owner_id', 'created_at', 'updated_at', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('account_roles', [
            'id', 'key', 'name', 'description', 'system', 'sort_order', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('account_user', [
            'id', 'account_id', 'user_id', 'account_role_id', 'joined_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_users_public_id_is_unique(): void
    {
        $first = User::factory()->create();

        $second = User::factory()->make();
        $second->public_id = $first->public_id;

        $this->expectException(QueryException::class);

        $second->save();
    }

    public function test_accounts_owner_id_restricts_user_deletion(): void
    {
        $user = User::factory()->create();
        Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $this->expectException(QueryException::class);

        $user->delete();
    }

    public function test_current_account_id_is_nulled_when_account_is_deleted(): void
    {
        $user = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $user->id]);

        $user->current_account_id = $account->id;
        $user->save();

        // Hard delete to trigger the nullOnDelete FK action.
        $account->forceDelete();

        $this->assertNull($user->fresh()->current_account_id);
    }
}
