<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * The package's account routes must run inside the `web` middleware group by
 * default. Without it they get no session, no cookies, and no CSRF check: a
 * session-authenticated user can't be recognised, the switch controller's
 * flash never reaches the session, and the state-changing POST is unprotected.
 *
 * actingAs() sets the guard's user directly, so it cannot prove the session is
 * there. These tests instead assert on what only the web group produces — a
 * session cookie, the XSRF-TOKEN cookie, flashed session data, and a 419 for a
 * POST without a CSRF token.
 */
final class AccountRoutesWebMiddlewareTest extends HttpTestCase
{
    private const ROUTES = [
        'jamesgifford-auth.account.switch',
        'jamesgifford-auth.account.list',
    ];

    public function test_account_routes_are_registered_inside_the_web_group_behind_auth(): void
    {
        foreach (self::ROUTES as $name) {
            $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

            $this->assertContains('web', $middleware, "{$name} must run inside the web group.");
            $this->assertContains('auth', $middleware, "{$name} must require authentication.");
        }
    }

    public function test_switch_works_for_an_authenticated_user_with_a_session(): void
    {
        ['user' => $user] = $this->userWithAccount();
        $second = $this->makeAccountFor($user);

        $this->actingAs($user)
            ->from('/dashboard')
            ->post(route('jamesgifford-auth.account.switch', $second))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'account-switched')
            ->assertCookie((string) config('session.cookie'))
            ->assertCookie('XSRF-TOKEN');

        $this->assertSame($second->id, $user->fresh()->current_account_id);
    }

    public function test_switch_rejects_a_post_without_a_csrf_token(): void
    {
        $this->enforceCsrfProtection();
        ['user' => $user, 'account' => $current] = $this->userWithAccount();
        $second = $this->makeAccountFor($user);

        $this->actingAs($user)
            ->post(route('jamesgifford-auth.account.switch', $second))
            ->assertStatus(419);

        $this->actingAs($user)
            ->postJson(route('jamesgifford-auth.account.switch', $second))
            ->assertStatus(419);

        $this->assertSame($current->id, $user->fresh()->current_account_id);
    }

    public function test_switch_accepts_a_post_carrying_the_session_csrf_token(): void
    {
        $this->enforceCsrfProtection();
        ['user' => $user] = $this->userWithAccount();
        $second = $this->makeAccountFor($user);

        $this->actingAs($user)
            ->withSession(['_token' => 'csrf-test-token'])
            ->post(route('jamesgifford-auth.account.switch', $second), ['_token' => 'csrf-test-token'])
            ->assertRedirect()
            ->assertSessionHas('status', 'account-switched');

        $this->assertSame($second->id, $user->fresh()->current_account_id);
    }

    public function test_switch_redirects_a_guest_to_login(): void
    {
        ['account' => $account] = $this->userWithAccount();

        $this->post(route('jamesgifford-auth.account.switch', $account))
            ->assertRedirect(route('login'));
    }

    public function test_list_works_for_an_authenticated_user_with_a_session(): void
    {
        ['user' => $user, 'account' => $account] = $this->userWithAccount();

        $this->actingAs($user)
            ->getJson(route('jamesgifford-auth.account.list'))
            ->assertOk()
            ->assertJsonFragment(['public_id' => $account->public_id, 'is_current' => true])
            ->assertCookie((string) config('session.cookie'))
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_list_rejects_a_guest(): void
    {
        $this->getJson(route('jamesgifford-auth.account.list'))->assertUnauthorized();

        $this->get(route('jamesgifford-auth.account.list'))->assertRedirect(route('login'));
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('/login', fn () => 'login')->name('login');
    }
}
