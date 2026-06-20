<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JamesGifford\Auth\Exceptions\NotAMemberException;
use JamesGifford\Auth\Models\Account;

/**
 * Switch the authenticated user's current account.
 *
 * Frontend-agnostic: returns only a redirect (web) or JSON (API) — never a
 * view. Delegates entirely to the HasAccounts::switchToAccount() primitive,
 * which validates membership and persists current_account_id; this controller
 * just translates that into an HTTP response.
 */
final class AccountSwitchController
{
    public function __invoke(Request $request, Account $account): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        try {
            $user->switchToAccount($account);
        } catch (NotAMemberException) {
            // switchToAccount() throws when the user isn't a member. Reject
            // without leaking whether the account exists; never render a view.
            if ($request->expectsJson()) {
                return response()->json(['message' => 'You are not a member of that account.'], 403);
            }

            $this->flash($request, 'error', 'You are not a member of that account.');

            return back();
        }

        if ($request->expectsJson()) {
            return response()->json(['current_account' => $account->public_id]);
        }

        $this->flash($request, 'status', 'account-switched');

        return back();
    }

    /**
     * Flash only when a session is available, so the controller stays usable on
     * stateless (API) stacks that have no session middleware.
     */
    private function flash(Request $request, string $key, string $value): void
    {
        if ($request->hasSession()) {
            $request->session()->flash($key, $value);
        }
    }
}
