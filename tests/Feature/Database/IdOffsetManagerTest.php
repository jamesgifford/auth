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
 * The suite's default database is MariaDB (the package's real target), where
 * the offset ACTUALLY takes effect — asserted below by inserting rows and
 * checking their ids. Driver-specific branches are guarded: the sqlite
 * graceful-no-op test runs only under `composer test:sqlite`, and the
 * real-effect test only on a real driver. Pure statementFor() generation
 * tests run everywhere.
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
        if ($this->databaseDriver() !== 'sqlite') {
            $this->markTestSkipped('Asserts the sqlite no-op path; run via composer test:sqlite.');
        }

        config(['jamesgifford.auth.id_offsets' => ['users' => 11, 'accounts' => 1001]]);

        // Must not throw, despite SQLite being unable to honor the offset.
        $results = $this->manager()->apply();

        foreach ($results as $result) {
            $this->assertSame('sqlite', $result['driver']);
            $this->assertFalse($result['applied']);
            $this->assertStringContainsString("driver 'sqlite' does not support", $result['reason']);
        }
    }

    public function test_offsets_actually_take_effect_on_a_real_driver(): void
    {
        if ($this->databaseDriver() === 'sqlite') {
            $this->markTestSkipped('Real AUTO_INCREMENT behavior needs mysql/mariadb.');
        }

        config(['jamesgifford.auth.id_offsets' => ['users' => 11, 'accounts' => 1001]]);

        $results = $this->manager()->apply();

        foreach ($results as $result) {
            $this->assertTrue($result['applied'], "{$result['table']}: {$result['reason']}");
            $this->assertNotNull($result['statement']);
        }

        // The proof sqlite could never give: the next inserted rows land
        // exactly at the configured offsets.
        $userId = DB::table('users')->insertGetId([
            'name' => 'Offset Check',
            'email' => 'offset-check@example.test',
            'password' => bcrypt('secret'),
            'public_id' => 'usr_offsetcheck000000',
        ]);
        $this->assertSame(11, $userId);

        $accountId = DB::table('accounts')->insertGetId([
            'public_id' => 'account_offsetcheck00',
            'name' => 'Offset Check Workspace',
            'owner_id' => $userId,
        ]);
        $this->assertSame(1001, $accountId);
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

    // ---- Env-sourced (string) config values ----

    public function test_string_config_offsets_are_cast_to_int(): void
    {
        // config('...id_offsets') reads env vars (see config/auth.php), which
        // arrive as STRINGS. The manager must cast an integer-looking string.
        config(['jamesgifford.auth.id_offsets' => ['users' => '10000', 'accounts' => '500']]);

        $results = $this->manager()->apply();

        $this->assertSame(10000, $this->resultFor($results, 'users')['offset']);
        $this->assertSame(500, $this->resultFor($results, 'accounts')['offset']);
    }

    public function test_env_var_name_uses_the_prefixed_convention(): void
    {
        $this->assertSame('JAMESGIFFORD_AUTH_USERS_ID_OFFSET', IdOffsetManager::envKeyFor('users'));
        $this->assertSame('JAMESGIFFORD_AUTH_ACCOUNTS_ID_OFFSET', IdOffsetManager::envKeyFor('accounts'));
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
