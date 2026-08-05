<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\AuthServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function tearDown(): void
    {
        // Each test builds a fresh application and therefore fresh PDO
        // connections. On a real driver those linger until garbage collection,
        // and across the whole suite they accumulate faster than the server
        // reaps them — tripping MariaDB's max_connections mid-run ("Too many
        // connections"). Disconnect explicitly AFTER the parent teardown so
        // every connection is covered, including any the migration-rollback
        // callbacks opened. (A beforeApplicationDestroyed hook is NOT safe
        // here: those callbacks run LIFO, so it would fire before the
        // rollback — destroying a sqlite :memory: database it still needs.)
        $db = $this->app?->make('db');

        parent::tearDown();

        if ($db !== null) {
            foreach (array_keys($db->getConnections()) as $name) {
                $db->disconnect($name);
            }
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            AuthServiceProvider::class,
        ];
    }

    /**
     * Every test starts from an EMPTY schema. sqlite :memory: provided that
     * for free (the connection IS the database); on a real driver — the
     * suite's default is MariaDB, the package's actual target — the database
     * persists between tests, so anything a crashed or partially-rolled-back
     * test left behind would cascade into later tests ("table already
     * exists", duplicate keys). Purging here, before each test's migrations
     * run, restores the hermetic semantics the suite was written against.
     *
     * Subclasses that override this hook MUST call parent:: first.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->purgeRealDatabase();
    }

    /**
     * The default connection's driver name, for tests whose assertions are
     * legitimately driver-specific (e.g. ID offsets: a graceful no-op on
     * sqlite, a real AUTO_INCREMENT change on mysql/mariadb).
     */
    protected function databaseDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    private function purgeRealDatabase(): void
    {
        if ($this->databaseDriver() === 'sqlite') {
            return;
        }

        Schema::dropAllTables();
    }
}
