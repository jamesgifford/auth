<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;

/**
 * The command is exercised on SQLite, where the offset is a documented no-op.
 * These tests assert it runs cleanly and reports sensibly — the real offset
 * behavior is verified manually on MySQL/MariaDB or PostgreSQL.
 */
class AuthApplyIdOffsetsCommandTest extends AccountsTestCase
{
    public function test_runs_without_error_when_no_offsets_configured(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => null]]);

        $this->artisan('jamesgifford:auth:apply-id-offsets')
            ->expectsOutputToContain('users: skipped — no offset configured')
            ->expectsOutputToContain('accounts: skipped — no offset configured')
            ->expectsOutputToContain('No ID offsets applied (nothing to do).')
            ->assertSuccessful();
    }

    public function test_reports_unsupported_driver_for_configured_offsets_on_sqlite(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => 1001]]);

        // SQLite cannot honor offsets: the command must still exit success and
        // report the skip clearly (no exception, no failure).
        $this->artisan('jamesgifford:auth:apply-id-offsets')
            ->expectsOutputToContain("accounts: skipped — driver 'sqlite' does not support")
            ->assertSuccessful();
    }
}
