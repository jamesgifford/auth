<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use JamesGifford\Auth\Accounts\Services\CurrentAccountService;
use JamesGifford\Auth\Events\CurrentAccountChanged;
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
 *  - Current account gone (deleted or membership lost): redirect to
 *    `http.middleware.redirect_missing_to` (after clearing it) when
 *    configured, else fall back to the floating behavior — which moves the
 *    user straight to their first remaining account, or clears it when they
 *    have none.
 *
 * Every change goes through {@see CurrentAccountService}, so the request's
 * user instance sees the new currentAccount immediately and
 * {@see CurrentAccountChanged} fires once per real change — a replacement is
 * reported as one change from the stale account to the new one.
 */
final class EnsureCurrentAccount
{
    public function __construct(
        private readonly CurrentAccountService $currentAccounts = new CurrentAccountService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Model || ! method_exists($user, 'belongsToAccount')) {
            return $next($request);
        }

        if ($user->getAttribute('current_account_id') !== null) {
            // null when the account is soft-deleted/gone
            $account = $user->getRelationValue('currentAccount');

            if ($account !== null && $user->belongsToAccount($account)) {
                return $next($request);
            }

            // The current account was deleted, or the user lost membership.
            return $this->resolveMissing($user) ?? $next($request);
        }

        return $this->resolveFloating($user) ?? $next($request);
    }

    /**
     * No usable current account. Redirect when configured (clearing a stale
     * pointer first), else move the user to their first account — or clear
     * the pointer when they have none — and continue. Returns null to continue.
     */
    private function resolveFloating(Model $user): ?Response
    {
        $route = config('jamesgifford.auth.http.middleware.redirect_floating_to');
        if (is_string($route) && $route !== '') {
            $this->currentAccounts->set($user, null);

            return redirect()->route($route);
        }

        // The consumer's User model is documented to use HasAccounts; its
        // concrete class is config-resolved, so PHPStan sees only Model here.
        $first = $user->accounts()->first(); // @phpstan-ignore method.notFound
        if ($first !== null) {
            $user->switchToAccount($first); // @phpstan-ignore method.notFound
        } else {
            $this->currentAccounts->set($user, null);
        }

        return null;
    }

    /**
     * The user's current account is gone. Redirect when configured (after
     * clearing it), else fall back to the floating behavior. Returns null to
     * continue.
     */
    private function resolveMissing(Model $user): ?Response
    {
        $route = config('jamesgifford.auth.http.middleware.redirect_missing_to');
        if (is_string($route) && $route !== '') {
            $this->currentAccounts->set($user, null);

            return redirect()->route($route);
        }

        return $this->resolveFloating($user);
    }
}
