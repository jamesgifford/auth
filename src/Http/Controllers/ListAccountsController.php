<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JamesGifford\Auth\Models\Account;

/**
 * Return the authenticated user's accounts as JSON so any frontend can render
 * its own account switcher. Frontend-agnostic: never returns a view.
 *
 * The payload is intentionally minimal — public_id, name, and which account is
 * current — so it travels well across Livewire, Inertia, Blade, or API stacks.
 */
final class ListAccountsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->current_account_id;

        $accounts = $user->accounts()
            ->get()
            ->map(static fn (Account $account): array => [
                'public_id' => $account->public_id,
                'name' => $account->name,
                'is_current' => $account->getKey() === $currentId,
            ])
            ->values();

        return response()->json(['accounts' => $accounts]);
    }
}
