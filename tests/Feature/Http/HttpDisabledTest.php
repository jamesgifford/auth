<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use JamesGifford\Auth\Tests\TestCase;

/**
 * When the HTTP plumbing is disabled (http.enabled = false, as set by
 * `install --without-http`), the service provider must register no routes and
 * no middleware alias.
 */
class HttpDisabledTest extends TestCase
{
    public function test_routes_are_not_registered_when_http_is_disabled(): void
    {
        $this->assertFalse(Route::has('jamesgifford-auth.account.switch'));
        $this->assertFalse(Route::has('jamesgifford-auth.account.list'));
    }

    public function test_middleware_alias_is_not_registered_when_http_is_disabled(): void
    {
        $aliases = $this->app['router']->getMiddleware();

        $this->assertArrayNotHasKey('auth.current-account', $aliases);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('jamesgifford.auth.http.enabled', false);
    }
}
