<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use JamesGifford\Auth\Http\Controllers\AccountSwitchController;
use JamesGifford\Auth\Http\Controllers\ListAccountsController;

/*
| Package HTTP routes for account management. Loaded by AuthServiceProvider
| only when config('jamesgifford.auth.http.enabled') is true. The route names
| are namespaced (jamesgifford-auth.*) so they won't collide with consumer
| routes. {account} is resolved by public_id via route-model binding.
|
| The middleware comes from config('jamesgifford.auth.http.routes.middleware'),
| defaulting to the `web` group behind `auth`: without the web group there is
| no session, so a session-authenticated user can't be recognised and the
| switch POST has no CSRF protection. The default lives here as well as in the
| config file because a config published before the key existed replaces the
| whole `http` block (mergeConfigFrom merges top-level keys only). A null or
| empty value also gets the default, so the routes are never left without auth.
|
| SubstituteBindings is always appended (Laravel de-duplicates it when the
| configured group already includes it), so {account} binding still works when
| the configured stack has no group that brings it along — e.g. ['auth'].
|
| The {account} parameter is bound by AuthServiceProvider::boot() to the
| CONFIGURED account class (Router::model + PackageModels::account()), so a
| models.account override flows through to these routes. The binder cannot
| live here: route files are skipped entirely under route:cache.
*/

$middleware = (array) config('jamesgifford.auth.http.routes.middleware') ?: ['web', 'auth'];

Route::middleware([...$middleware, SubstituteBindings::class])
    ->prefix('account')
    ->name('jamesgifford-auth.account.')
    ->group(function (): void {
        Route::post('switch/{account}', AccountSwitchController::class)->name('switch');
        Route::get('list', ListAccountsController::class)->name('list');
    });
