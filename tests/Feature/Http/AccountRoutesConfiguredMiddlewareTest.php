<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use Illuminate\Support\Facades\Route;

/**
 * `http.routes.middleware` replaces the default `['web', 'auth']` stack, for
 * apps that serve the account routes some other way (e.g. a token-authenticated
 * API). Route-model binding must keep working even when the configured stack
 * has no group that brings SubstituteBindings along.
 */
final class AccountRoutesConfiguredMiddlewareTest extends HttpTestCase
{
    public function test_configured_middleware_replaces_the_web_default(): void
    {
        foreach (['jamesgifford-auth.account.switch', 'jamesgifford-auth.account.list'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

            $this->assertContains('auth', $middleware);
            $this->assertContains('throttle:60,1', $middleware);
            $this->assertNotContains('web', $middleware, "{$name} should use only the configured middleware.");
        }
    }

    public function test_account_binding_still_resolves_without_a_group_that_substitutes_bindings(): void
    {
        ['user' => $user] = $this->userWithAccount();
        $second = $this->makeAccountFor($user);

        $this->actingAs($user)
            ->postJson(route('jamesgifford-auth.account.switch', $second))
            ->assertOk()
            ->assertJson(['current_account' => $second->public_id]);

        $this->assertSame($second->id, $user->fresh()->current_account_id);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Pre-boot: the route file reads it when the provider loads the routes.
        // No group here, so nothing brings SubstituteBindings along.
        $app['config']->set('jamesgifford.auth.http.routes.middleware', ['auth', 'throttle:60,1']);
    }
}
