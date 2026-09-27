<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use Illuminate\Support\Facades\Route;

/**
 * An empty `http.routes.middleware` (null or []) must not register the account
 * routes without `auth`: it falls back to the `['web', 'auth']` default.
 */
final class AccountRoutesEmptyMiddlewareConfigTest extends HttpTestCase
{
    public function test_an_empty_middleware_list_falls_back_to_the_web_and_auth_default(): void
    {
        foreach (['jamesgifford-auth.account.switch', 'jamesgifford-auth.account.list'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware, "{$name} must never be registered without auth.");
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('jamesgifford.auth.http.routes.middleware', null);
    }
}
