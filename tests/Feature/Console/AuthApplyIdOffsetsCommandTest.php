<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

/**
 * On the suite's default MariaDB the command genuinely applies offsets (and
 * the effect is asserted by inserting a row); on sqlite it is a documented,
 * cleanly-reported no-op. Each branch is guarded by the active driver.
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
        if ($this->databaseDriver() !== 'sqlite') {
            $this->markTestSkipped('Asserts the sqlite no-op path; run via composer test:sqlite.');
        }

        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => 1001]]);

        // SQLite cannot honor offsets: the command must still exit success and
        // report the skip clearly (no exception, no failure).
        $this->artisan('jamesgifford:auth:apply-id-offsets')
            ->expectsOutputToContain("accounts: skipped — driver 'sqlite' does not support")
            ->assertSuccessful();
    }

    public function test_applies_offsets_for_real_on_a_real_driver(): void
    {
        if ($this->databaseDriver() === 'sqlite') {
            $this->markTestSkipped('Real AUTO_INCREMENT behavior needs mysql/mariadb.');
        }

        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => 1001]]);

        $this->artisan('jamesgifford:auth:apply-id-offsets')
            ->expectsOutputToContain('accounts: auto-increment set to 1001')
            ->expectsOutputToContain('Applied ID offsets to 1 table(s).')
            ->assertSuccessful();

        // The counter really moved: the FIRST account inserted after the
        // offset lands exactly at it.
        $owner = User::factory()->create();
        $accountId = DB::table('accounts')->insertGetId([
            'public_id' => 'account_offsetcmd0000',
            'name' => 'Offset Command Check',
            'owner_id' => $owner->id,
        ]);
        $this->assertSame(1001, $accountId);
    }
}
