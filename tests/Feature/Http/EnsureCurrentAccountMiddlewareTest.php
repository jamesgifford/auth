<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Events\CurrentAccountChanged;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class EnsureCurrentAccountMiddlewareTest extends HttpTestCase
{
    public function test_user_with_valid_current_account_passes_through(): void
    {
        ['user' => $user] = $this->userWithAccount();

        $this->actingAs($user)->get('/_mw/ensure')->assertOk()->assertSee('ok');
    }

    public function test_no_authenticated_user_passes_through(): void
    {
        $this->get('/_mw/ensure')->assertOk()->assertSee('ok');
    }

    public function test_floating_user_with_accounts_gets_one_assigned(): void
    {
        $user = User::factory()->create();
        $account = $this->makeAccountFor($user); // member, but current_account_id still null
        $this->assertNull($user->fresh()->current_account_id);

        $this->actingAs($user->fresh())->get('/_mw/ensure')->assertOk()->assertSee('ok');

        $this->assertSame($account->id, $user->fresh()->current_account_id);
    }

    public function test_floating_user_with_no_accounts_passes_through(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/_mw/ensure')->assertOk()->assertSee('ok');

        $this->assertNull($user->fresh()->current_account_id);
    }

    public function test_floating_user_is_redirected_when_config_destination_set(): void
    {
        config(['jamesgifford.auth.http.middleware.redirect_floating_to' => 'http-test.floating']);

        $user = User::factory()->create();
        $this->makeAccountFor($user); // has an account, but is floating

        $this->actingAs($user->fresh())
            ->get('/_mw/ensure')
            ->assertRedirect(route('http-test.floating'));

        // Redirected instead of auto-assigning.
        $this->assertNull($user->fresh()->current_account_id);
    }

    public function test_deleted_current_account_is_cleared_and_redirected_per_config(): void
    {
        config(['jamesgifford.auth.http.middleware.redirect_missing_to' => 'http-test.missing']);

        ['user' => $user, 'account' => $account] = $this->userWithAccount();
        // Soft-delete the account directly (not via the service, which would
        // also null current_account_id) so the column still points at it.
        $account->delete();

        $this->actingAs($user->fresh())
            ->get('/_mw/ensure')
            ->assertRedirect(route('http-test.missing'));

        $this->assertNull($user->fresh()->current_account_id);
    }

    public function test_deleted_current_account_falls_back_to_floating_when_no_missing_destination(): void
    {
        // redirect_missing_to is null → fall back to floating behavior, which
        // (also null) auto-assigns a remaining account.
        ['user' => $user, 'account' => $current] = $this->userWithAccount();
        $other = $this->makeAccountFor($user);
        $current->delete();

        $this->actingAs($user->fresh())->get('/_mw/ensure')->assertOk()->assertSee('ok');

        $this->assertSame($other->id, $user->fresh()->current_account_id);
    }

    public function test_a_valid_current_account_dispatches_nothing(): void
    {
        ['user' => $user] = $this->userWithAccount();
        Event::fake([CurrentAccountChanged::class]);

        $this->actingAs($user)->get('/_mw/ensure')->assertOk();

        Event::assertNotDispatched(CurrentAccountChanged::class);
    }

    public function test_auto_assigning_a_floating_user_reports_the_change(): void
    {
        $user = User::factory()->create();
        $account = $this->makeAccountFor($user);
        Event::fake([CurrentAccountChanged::class]);

        $this->actingAs($user->fresh())->get('/_mw/ensure')->assertOk();

        Event::assertDispatchedTimes(CurrentAccountChanged::class, 1);
        Event::assertDispatched(CurrentAccountChanged::class, fn (CurrentAccountChanged $event): bool => $event->user->id === $user->id
            && $event->previousAccount === null
            && $event->newAccount?->id === $account->id);
    }

    public function test_reassigning_a_deleted_current_account_reports_one_change_from_old_to_new(): void
    {
        ['user' => $user, 'account' => $current] = $this->userWithAccount();
        $other = $this->makeAccountFor($user);
        $current->delete();
        Event::fake([CurrentAccountChanged::class]);

        $this->actingAs($user->fresh())->get('/_mw/ensure')->assertOk();

        // One change straight from the stale account to its replacement — not
        // a clear followed by a separate assignment.
        Event::assertDispatchedTimes(CurrentAccountChanged::class, 1);
        Event::assertDispatched(CurrentAccountChanged::class, fn (CurrentAccountChanged $event): bool => $event->previousAccount?->id === $current->id
            && $event->newAccount?->id === $other->id);
    }

    public function test_clearing_a_deleted_current_account_before_redirecting_reports_the_change(): void
    {
        config(['jamesgifford.auth.http.middleware.redirect_missing_to' => 'http-test.missing']);
        ['user' => $user, 'account' => $account] = $this->userWithAccount();
        $account->delete();
        Event::fake([CurrentAccountChanged::class]);

        $this->actingAs($user->fresh())->get('/_mw/ensure')->assertRedirect(route('http-test.missing'));

        Event::assertDispatchedTimes(CurrentAccountChanged::class, 1);
        Event::assertDispatched(CurrentAccountChanged::class, fn (CurrentAccountChanged $event): bool => $event->previousAccount?->id === $account->id
            && $event->newAccount === null);
    }

    public function test_clearing_a_lost_membership_with_no_other_account_reports_the_change(): void
    {
        ['user' => $owner, 'account' => $account] = $this->userWithAccount();
        $member = User::factory()->create();
        app(AccountService::class)->attachUser($account, $member, 'member');
        $member->switchToAccount($account);
        // Remove the membership behind the service's back, so the pointer is left dangling.
        $member->memberships()->delete();
        Event::fake([CurrentAccountChanged::class]);

        $this->actingAs($member->fresh())->get('/_mw/ensure')->assertOk();

        Event::assertDispatchedTimes(CurrentAccountChanged::class, 1);
        Event::assertDispatched(CurrentAccountChanged::class, fn (CurrentAccountChanged $event): bool => $event->previousAccount?->id === $account->id
            && $event->newAccount === null);
        $this->assertNull($member->fresh()->current_account_id);
    }

    public function test_downstream_code_sees_the_reassigned_current_account_in_the_same_request(): void
    {
        ['user' => $user, 'account' => $current] = $this->userWithAccount();
        $other = $this->makeAccountFor($user);
        $current->delete();

        // The middleware's own lookup caches the (now missing) relation on the
        // instance the guard holds; the reassignment must replace that cache.
        $this->actingAs($user)
            ->get('/_mw/current')
            ->assertOk()
            ->assertSee($other->public_id);
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('auth.current-account')->get('/_mw/ensure', fn () => 'ok');
        $router->middleware('auth.current-account')->get('/_mw/current', fn (Request $request) => (string) $request->user()->currentAccount?->public_id);
        $router->get('/_mw/floating', fn () => 'floating-target')->name('http-test.floating');
        $router->get('/_mw/missing', fn () => 'missing-target')->name('http-test.missing');
    }
}
