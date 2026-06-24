<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JamesGifford\Auth\Database\IdOffsetManager;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;

/**
 * NOTE ON TEST SCOPE
 * ------------------
 * These tests run on SQLite (Testbench), which does NOT support setting an
 * auto-increment start the way MySQL/MariaDB and PostgreSQL do. So we test the
 * LOGIC and the per-driver SQL GENERATION — NOT the actual offset taking effect.
 *
 * The real auto-increment behavior MUST be verified manually against a live
 * MySQL/MariaDB or PostgreSQL database; SQLite cannot honor these statements, so
 * asserting offset behavior here would be a false claim of verification.
 */
class IdOffsetManagerTest extends AccountsTestCase
{
    // ---- Per-driver SQL generation (the part SQLite can't execute) ----

    public function test_generates_mysql_and_mariadb_alter_table_statements(): void
    {
        $manager = $this->manager();

        $this->assertSame(
            'ALTER TABLE `accounts` AUTO_INCREMENT = 1001',
            $manager->statementFor('mysql', 'accounts', 1001),
        );
        $this->assertSame(
            'ALTER TABLE `users` AUTO_INCREMENT = 11',
            $manager->statementFor('mariadb', 'users', 11),
        );
    }

    public function test_generates_postgres_alter_sequence_statement(): void
    {
        // {table}_id_seq is Laravel's default sequence for an id() column.
        $this->assertSame(
            'ALTER SEQUENCE "accounts_id_seq" RESTART WITH 1001',
            $this->manager()->statementFor('pgsql', 'accounts', 1001),
        );
    }

    public function test_returns_null_statement_for_sqlite_and_unknown_drivers(): void
    {
        $manager = $this->manager();

        $this->assertNull($manager->statementFor('sqlite', 'accounts', 1001));
        $this->assertNull($manager->statementFor('sqlsrv', 'accounts', 1001));
    }

    // ---- apply() logic (runs on SQLite without executing the ALTER) ----

    public function test_null_offsets_produce_no_statements(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => null]]);

        $results = $this->manager()->apply();

        foreach ($results as $result) {
            $this->assertFalse($result['applied']);
            $this->assertSame('no offset configured', $result['reason']);
            $this->assertNull($result['statement']);
        }
    }

    public function test_sqlite_driver_is_a_graceful_no_op_for_configured_offsets(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => 11, 'accounts' => 1001]]);

        // Must not throw, despite SQLite being unable to honor the offset.
        $results = $this->manager()->apply();

        foreach ($results as $result) {
            $this->assertSame('sqlite', $result['driver']);
            $this->assertFalse($result['applied']);
            $this->assertStringContainsString("driver 'sqlite' does not support", $result['reason']);
        }
    }

    public function test_offset_at_or_below_existing_max_id_is_skipped_with_a_reason(): void
    {
        // A real row above the offset — the engine would ignore the offset, so
        // we skip and report rather than appear to succeed. (Driver-agnostic, so
        // this branch is observable on SQLite.)
        DB::table('users')->insert([
            'id' => 2000,
            'name' => 'High',
            'email' => 'high@example.test',
            'password' => bcrypt('secret'),
            'public_id' => 'usr_highid0000000000a',
        ]);

        config(['jamesgifford.auth.id_offsets' => ['users' => 1001, 'accounts' => null]]);

        $users = $this->resultFor($this->manager()->apply(), 'users');

        $this->assertFalse($users['applied']);
        $this->assertStringContainsString('already has ids up to 2000', $users['reason']);
        $this->assertStringContainsString('offset 1001', $users['reason']);
    }

    public function test_rejects_a_non_positive_integer_offset(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => 0]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid id offset for 'accounts'");

        $this->manager()->apply();
    }

    public function test_rejects_a_non_integer_offset(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['accounts' => 'eleven']]);

        $this->expectException(InvalidArgumentException::class);

        $this->manager()->apply();
    }

    public function test_result_structure_describes_each_table(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => 1001]]);

        $results = $this->manager()->apply();

        $this->assertCount(2, $results);
        foreach (['table', 'driver', 'offset', 'applied', 'reason', 'statement'] as $key) {
            $this->assertArrayHasKey($key, $results[0]);
        }
        $this->assertSame('users', $results[0]['table']);
        $this->assertSame('accounts', $results[1]['table']);
        $this->assertSame(1001, $results[1]['offset']);
    }

    // ---- Environment variable resolution (override / supplement) ----

    public function test_offset_is_supplied_by_an_environment_variable_when_config_is_null(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => null]]);
        $_SERVER[IdOffsetManager::envKeyFor('users')] = '10000'; // env vars are strings

        try {
            $users = $this->resultFor($this->manager()->apply(), 'users');

            // String env value is cast to int and used (supplements null config).
            $this->assertSame(10000, $users['offset']);
        } finally {
            unset($_SERVER[IdOffsetManager::envKeyFor('users')]);
        }
    }

    public function test_environment_variable_overrides_a_config_declared_offset(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => 11, 'accounts' => null]]);
        $_SERVER[IdOffsetManager::envKeyFor('users')] = '99999';

        try {
            $users = $this->resultFor($this->manager()->apply(), 'users');

            // Env wins over the config literal.
            $this->assertSame(99999, $users['offset']);
        } finally {
            unset($_SERVER[IdOffsetManager::envKeyFor('users')]);
        }
    }

    private function manager(): IdOffsetManager
    {
        return $this->app->make(IdOffsetManager::class);
    }

    /**
     * @param  list<array{table: string, driver: string, offset: int|null, applied: bool, reason: string, statement: string|null}>  $results
     * @return array{table: string, driver: string, offset: int|null, applied: bool, reason: string, statement: string|null}
     */
    private function resultFor(array $results, string $table): array
    {
        foreach ($results as $result) {
            if ($result['table'] === $table) {
                return $result;
            }
        }

        $this->fail("No result for table '{$table}'.");
    }
}
