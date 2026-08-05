<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\SystemRole;
use JamesGifford\Auth\Tests\Support\Fixtures\Overrides\OverrideAccount;
use JamesGifford\Auth\Tests\Support\Fixtures\Overrides\OverrideAccountRole;
use JamesGifford\Auth\Tests\Support\Fixtures\Overrides\OverrideAccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

/**
 * The HTTP surface must honor config('jamesgifford.auth.models.account'):
 * the provider registers an explicit {account} route binder for the
 * CONFIGURED class, so controllers receive the consumer's subclass — not
 * always the package base — while keeping public_id lookups and 404s intact.
 */
final class RouteBindingOverrideTest extends HttpTestCase
{
    public function test_route_binding_resolves_the_configured_account_subclass(): void
    {
        ['user' => $user, 'account' => $account] = $this->userWithAccount();

        Route::get('/_probe/{account}', fn (Account $bound): string => $bound::class)
            ->middleware(SubstituteBindings::class);

        $response = $this->actingAs($user)->get('/_probe/'.$account->public_id);

        $response->assertOk();
        $this->assertSame(OverrideAccount::class, $response->getContent());
    }

    public function test_switch_route_works_with_the_configured_subclass(): void
    {
        ['user' => $owner] = $this->userWithAccount();
        $member = User::factory()->create();
        $other = $this->makeAccountFor($owner);
        app(AccountService::class)
            ->attachUser($other, $member, SystemRole::MEMBER);

        $this->actingAs($member)
            ->postJson(route('jamesgifford-auth.account.switch', $other))
            ->assertOk();

        $this->assertSame($other->id, $member->fresh()->current_account_id);
    }

    public function test_unknown_public_id_yields_404(): void
    {
        ['user' => $user] = $this->userWithAccount();

        $this->actingAs($user)
            ->postJson(route('jamesgifford-auth.account.switch', 'account_nonexistent00000000'))
            ->assertNotFound();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Set pre-boot: the provider captures the configured class when it
        // registers the {account} binder during boot.
        $app['config']->set('jamesgifford.auth.models.account', OverrideAccount::class);
        $app['config']->set('jamesgifford.auth.models.account_user', OverrideAccountUser::class);
        $app['config']->set('jamesgifford.auth.models.account_role', OverrideAccountRole::class);
    }
}
