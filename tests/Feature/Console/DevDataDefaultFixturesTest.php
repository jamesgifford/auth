<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

/**
 * Verifies the SHIPPED default dev-data config (config/dev-data.php) — these
 * tests deliberately do NOT override config('jamesgifford.dev-data'); they use
 * the merged package default, so they prove the default cast is real and
 * seedable out of the box.
 */
class DevDataDefaultFixturesTest extends AccountsTestCase
{
    private const PUBLISHED = 'jamesgifford'.DIRECTORY_SEPARATOR.'dev-data.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            @unlink(config_path(self::PUBLISHED));
        }
        parent::tearDown();
    }

    public function test_published_config_ships_pre_populated_with_the_default_cast(): void
    {
        @unlink(config_path(self::PUBLISHED));

        Artisan::call('vendor:publish', ['--tag' => 'jamesgifford-auth-dev-data', '--force' => true]);

        $contents = (string) file_get_contents(config_path(self::PUBLISHED));

        // Not empty — it ships with the full default cast.
        foreach ([
            'owner@dev.test', 'admin@dev.test', 'member@dev.test',
            'multi@dev.test', 'floating@dev.test', 'Acme Inc', 'Beta LLC',
        ] as $needle) {
            $this->assertStringContainsString($needle, $contents);
        }
    }

    public function test_published_config_keeps_the_password_env_sourced_with_no_literal(): void
    {
        @unlink(config_path(self::PUBLISHED));
        Artisan::call('vendor:publish', ['--tag' => 'jamesgifford-auth-dev-data', '--force' => true]);

        $contents = (string) file_get_contents(config_path(self::PUBLISHED));

        // Password comes from the environment, never a stored literal.
        $this->assertStringContainsString("env('DEV_USER_PASSWORD'", $contents);
        $this->assertStringNotContainsString("'password' => '", $contents);
    }

    public function test_default_config_seeds_the_whole_cast_with_invariants_intact(): void
    {
        $this->app['env'] = 'local'; // allow-listed in the default config

        Artisan::call('jamesgifford:auth:seed-dev-data');

        $this->app['env'] = 'testing';

        // All five users seeded.
        foreach (['owner', 'admin', 'member', 'multi', 'floating'] as $who) {
            $this->assertTrue(User::query()->where('email', "{$who}@dev.test")->exists(), "{$who} should be seeded");
        }

        $owner = User::query()->where('email', 'owner@dev.test')->firstOrFail();
        $admin = User::query()->where('email', 'admin@dev.test')->firstOrFail();
        $member = User::query()->where('email', 'member@dev.test')->firstOrFail();
        $multi = User::query()->where('email', 'multi@dev.test')->firstOrFail();

        $acme = Account::query()->where('name', 'Acme Inc')->firstOrFail();
        $beta = Account::query()->where('name', 'Beta LLC')->firstOrFail();

        // Single-owner invariant holds for both accounts (one owner membership each).
        $this->assertTrue($owner->isOwnerOf($acme));
        $this->assertTrue($multi->isOwnerOf($beta));
        $this->assertSame($owner->id, $acme->owner_id);
        $this->assertSame($multi->id, $beta->owner_id);
        $this->assertSame(1, $acme->memberships()->whereHas('role', fn ($q) => $q->where('key', 'owner'))->count());
        $this->assertSame(1, $beta->memberships()->whereHas('role', fn ($q) => $q->where('key', 'owner'))->count());

        // Roles are correct for the Acme members.
        $this->assertTrue($admin->hasRole($acme, 'admin'));
        $this->assertTrue($member->hasRole($acme, 'member'));
    }

    public function test_multi_account_user_has_two_accounts_and_floating_user_has_none(): void
    {
        $this->app['env'] = 'local';
        Artisan::call('jamesgifford:auth:seed-dev-data');
        $this->app['env'] = 'testing';

        $multi = User::query()->where('email', 'multi@dev.test')->firstOrFail();
        $floating = User::query()->where('email', 'floating@dev.test')->firstOrFail();

        // Owns Beta LLC AND a member of Acme Inc == two accounts.
        $this->assertSame(2, $multi->accounts()->count());
        $this->assertTrue($multi->isOwnerOf(Account::query()->where('name', 'Beta LLC')->firstOrFail()));

        // Floating: belongs to nothing.
        $this->assertSame(0, $floating->accounts()->count());
        $this->assertFalse($floating->hasAnyAccount());
    }

    public function test_default_seeded_password_is_the_env_value_hashed_not_plaintext(): void
    {
        $this->app['env'] = 'local';
        Artisan::call('jamesgifford:auth:seed-dev-data');
        $this->app['env'] = 'testing';

        $owner = User::query()->where('email', 'owner@dev.test')->firstOrFail();

        // Default fallback for DEV_USER_PASSWORD is 'password'; hashed at seed time.
        $this->assertTrue(Hash::check('password', $owner->password));
        $this->assertNotSame('password', $owner->password);
        $this->assertTrue(Hash::isHashed($owner->password));
    }
}
