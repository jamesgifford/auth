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
| SubstituteBindings is applied explicitly (rather than relying on the web/api
| group) so route-model binding works without forcing session/CSRF — keeping
| the endpoints usable from any frontend, including stateless API clients.
*/

Route::middleware(['auth', SubstituteBindings::class])
    ->prefix('account')
    ->name('jamesgifford-auth.account.')
    ->group(function (): void {
        Route::post('switch/{account}', AccountSwitchController::class)->name('switch');
        Route::get('list', ListAccountsController::class)->name('list');
    });
