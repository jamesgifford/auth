<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantee an authenticated user has a usable current account.
 *
 * Frontend-agnostic: any redirect destination comes from config (route names),
 * never hardcoded, and the middleware never assumes a route exists. It returns
 * the downstream response (continue) or a redirect — never a view.
 *
 * Behavior:
 *  - No authenticated user: pass through (let `auth` handle it).
 *  - Valid current account (set, still exists, still a member): continue.
 *  - Floating (no current account): auto-assign the first account, or redirect
 *    to `http.middleware.redirect_floating_to` when configured.
 *  - Current account gone (deleted or membership lost): clear it, then redirect
 *    to `http.middleware.redirect_missing_to` when configured, else fall back
 *    to the floating behavior.
 */
final class EnsureCurrentAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Model || ! method_exists($user, 'belongsToAccount')) {
            return $next($request);
        }

        if ($user->current_account_id !== null) {
            $account = $user->currentAccount; // null when the account is soft-deleted/gone

            if ($account !== null && $user->belongsToAccount($account)) {
                return $next($request);
            }

            // The current account was deleted, or the user lost membership.
            $user->current_account_id = null;
            $user->save();

            return $this->resolveMissing($user) ?? $next($request);
        }

        return $this->resolveFloating($user) ?? $next($request);
    }

    /**
     * A floating user (no current account). Redirect when configured, else
     * auto-assign their first account and continue. Returns null to continue.
     */
    private function resolveFloating(Model $user): ?Response
    {
        $route = config('jamesgifford.auth.http.middleware.redirect_floating_to');
        if (is_string($route) && $route !== '') {
            return redirect()->route($route);
        }

        $first = $user->accounts()->first();
        if ($first !== null) {
            $user->switchToAccount($first);
        }

        return null;
    }

    /**
     * The user's current account is gone. Redirect when configured, else fall
     * back to the floating behavior. Returns null to continue.
     */
    private function resolveMissing(Model $user): ?Response
    {
        $route = config('jamesgifford.auth.http.middleware.redirect_missing_to');
        if (is_string($route) && $route !== '') {
            return redirect()->route($route);
        }

        return $this->resolveFloating($user);
    }
}
