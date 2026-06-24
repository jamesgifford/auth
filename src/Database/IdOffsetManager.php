<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Sets the auto-increment starting value ("offset") for the users and accounts
 * tables from config('jamesgifford.auth.id_offsets'), so real records begin
 * above a chosen number.
 *
 * This is an INVOCABLE step (see jamesgifford:auth:apply-id-offsets), not bound
 * to a fixed install moment, because it must run AFTER any existing rows are in
 * place — typically: migrate → seed roles → (optional dev-data seed) →
 * apply offsets — so the counter lands above them.
 *
 * Driver support:
 *  - MySQL/MariaDB: ALTER TABLE `{table}` AUTO_INCREMENT = {offset}
 *  - PostgreSQL:    ALTER SEQUENCE "{table}_id_seq" RESTART WITH {offset}
 *                   ({table}_id_seq is Laravel's default sequence for an id()
 *                   column; identity columns would instead use
 *                   ALTER TABLE "{table}" ALTER COLUMN id RESTART WITH {offset}.)
 *  - SQLite / other: no-op (skipped gracefully; never errors).
 *
 * Table names come from a fixed internal allowlist (users, accounts) and the
 * offset is validated to be a positive integer, so values are embedded safely.
 */
final class IdOffsetManager
{
    /**
     * The tables this manager can offset, in the order they are reported.
     */
    private const TABLES = ['users', 'accounts'];

    private const SUPPORTED_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    /**
     * Apply the configured offsets and return a per-table report.
     *
     * @return list<array{table: string, driver: string, offset: int|null, applied: bool, reason: string, statement: string|null}>
     *
     * @throws InvalidArgumentException when a configured offset is not a positive integer
     */
    public function apply(): array
    {
        /** @var array<string, mixed> $offsets */
        $offsets = (array) config('jamesgifford.auth.id_offsets', []);

        $results = [];
        foreach (self::TABLES as $table) {
            $results[] = $this->applyToTable($table, $this->normalizeOffset($offsets[$table] ?? null));
        }

        return $results;
    }

    /**
     * The environment variable name an offset is read from in the package
     * config, e.g. JAMESGIFFORD_AUTH_USERS_ID_OFFSET. The env() call itself
     * lives in config/auth.php (env must only be read in config); this method is
     * the single source of the NAME, so the setup command's educational copy and
     * the tests stay in sync with the config.
     */
    public static function envKeyFor(string $table): string
    {
        return 'JAMESGIFFORD_AUTH_'.strtoupper($table).'_ID_OFFSET';
    }

    /**
     * The driver-appropriate statement to set the auto-increment start, or null
     * when the driver doesn't support it. Pure (no execution) so the exact SQL
     * can be asserted per driver in tests — SQLite cannot execute it.
     */
    public function statementFor(string $driver, string $table, int $offset): ?string
    {
        return match ($driver) {
            'mysql', 'mariadb' => "ALTER TABLE `{$table}` AUTO_INCREMENT = {$offset}",
            'pgsql' => 'ALTER SEQUENCE "'.$table.'_id_seq" RESTART WITH '.$offset,
            default => null,
        };
    }

    /**
     * Normalize a configured offset. The value comes from config (which may
     * have read it from an environment variable, so it arrives as a string):
     * an integer-looking string is cast to int; anything else is passed through
     * untouched for validation to accept (int) or reject.
     */
    private function normalizeOffset(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return $value;
    }

    /**
     * @return array{table: string, driver: string, offset: int|null, applied: bool, reason: string, statement: string|null}
     */
    private function applyToTable(string $table, mixed $offset): array
    {
        $driver = $this->driver();

        if ($offset === null) {
            return $this->result($table, $driver, null, false, 'no offset configured');
        }

        $this->assertValidOffset($table, $offset);

        if (! Schema::hasTable($table)) {
            return $this->result($table, $driver, $offset, false, "table '{$table}' not found");
        }

        // Safety: an offset at or below the current max id is meaningless (the
        // engine would ignore it / continue from max+1). Skip and report rather
        // than appear to succeed. This guard is driver-agnostic.
        $maxId = (int) (DB::table($table)->max('id') ?? 0);
        if ($offset <= $maxId) {
            return $this->result(
                $table,
                $driver,
                $offset,
                false,
                "table already has ids up to {$maxId} (>= offset {$offset})",
            );
        }

        $statement = $this->statementFor($driver, $table, $offset);
        if (! in_array($driver, self::SUPPORTED_DRIVERS, true) || $statement === null) {
            return $this->result(
                $table,
                $driver,
                $offset,
                false,
                "driver '{$driver}' does not support id offsets",
            );
        }

        DB::statement($statement);

        return $this->result($table, $driver, $offset, true, "auto-increment set to {$offset}", $statement);
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    private function assertValidOffset(string $table, mixed $offset): void
    {
        if (! is_int($offset) || $offset < 1) {
            throw new InvalidArgumentException(sprintf(
                "Invalid id offset for '%s': must be a positive integer, got %s.",
                $table,
                var_export($offset, true),
            ));
        }
    }

    /**
     * @return array{table: string, driver: string, offset: int|null, applied: bool, reason: string, statement: string|null}
     */
    private function result(
        string $table,
        string $driver,
        ?int $offset,
        bool $applied,
        string $reason,
        ?string $statement = null,
    ): array {
        return [
            'table' => $table,
            'driver' => $driver,
            'offset' => $offset,
            'applied' => $applied,
            'reason' => $reason,
            'statement' => $statement,
        ];
    }
}
