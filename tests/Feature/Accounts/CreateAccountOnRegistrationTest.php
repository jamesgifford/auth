<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Events\AccountCreated;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\SystemRole;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class CreateAccountOnRegistrationTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_registering_creates_an_account_the_user_owns(): void
    {
        $user = User::factory()->create();

        event(new Registered($user));

        $this->assertSame(1, Account::query()->count());

        $account = Account::query()->firstOrFail();
        $this->assertSame($user->id, $account->owner_id);
        $this->assertTrue($user->fresh()->isOwnerOf($account));
    }

    public function test_account_name_reflects_the_configured_template(): void
    {
        config(['jamesgifford.auth.accounts.default_name_template' => "{name}'s Account"]);
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        event(new Registered($user));

        $this->assertSame("Ada Lovelace's Account", Account::query()->firstOrFail()->name);
    }

    public function test_current_account_id_is_set_to_the_new_account(): void
    {
        $user = User::factory()->create();

        event(new Registered($user));

        $account = Account::query()->firstOrFail();
        $fresh = $user->fresh();

        $this->assertSame($account->id, $fresh->current_account_id);
        $this->assertTrue($fresh->currentAccount->is($account));
    }

    public function test_owner_membership_row_is_created(): void
    {
        $user = User::factory()->create();

        event(new Registered($user));

        $account = Account::query()->firstOrFail();
        $membership = AccountUser::query()
            ->where('account_id', $account->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(SystemRole::OWNER, $membership->role->key);
    }

    public function test_does_not_create_a_second_account_for_a_user_who_already_has_one(): void
    {
        // A user who already belongs to an account (e.g. created through a flow
        // that both fires Registered and creates an account explicitly).
        $user = User::factory()->create();
        app(AccountService::class)->create($user);
        $this->assertSame(1, Account::query()->count());

        event(new Registered($user));

        // Idempotent: no duplicate account.
        $this->assertSame(1, Account::query()->count());
    }

    public function test_user_with_blank_name_still_gets_a_sensible_account_name(): void
    {
        // Empty name must not yield a broken "'s Account".
        $user = User::factory()->create(['name' => '']);

        event(new Registered($user));

        $name = Account::query()->firstOrFail()->name;

        $this->assertSame("User's Account", $name);
        $this->assertStringStartsNotWith("'s", $name);
    }

    public function test_account_created_event_is_dispatched(): void
    {
        // Fake only AccountCreated so the real Registered listener still runs;
        // the account is created either way, and the downstream event is recorded.
        Event::fake([AccountCreated::class]);
        $user = User::factory()->create();

        event(new Registered($user));

        Event::assertDispatched(AccountCreated::class, 1);
    }
}
