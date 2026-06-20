<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Listeners;

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Concerns\HasAccounts;

/**
 * Auto-creates a personal account for a newly registered user and sets it as
 * their current account. This is the package's default account behavior; it
 * activates simply by the package being installed.
 *
 * Fires on Laravel's {@see Registered} event. Users created through flows that
 * do NOT dispatch Registered — database seeders, model factories, some
 * admin-created-user paths — will NOT auto-get an account; call
 * {@see AccountService::create()} explicitly for those.
 *
 * Idempotent: if the user already belongs to any account (a double-fire, or a
 * flow that both fires Registered and creates an account explicitly), it does
 * nothing.
 *
 * Deliberately thin: it only decides whether/how, then delegates account
 * creation to {@see AccountService::create()} and current-account selection to
 * {@see HasAccounts::switchToAccount()} — no account-creation logic is inlined.
 * Making this configurable later is a localized change at the wiring point in
 * the service provider, not a restructuring here.
 */
final class CreateAccountOnRegistration
{
    public function __construct(
        private readonly AccountService $accounts,
    ) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

        // The package's User model uses HasAccounts. If it doesn't (a
        // non-package user model, or a misconfigured install), there is
        // nothing here to wire up.
        if (! $user instanceof Model || ! method_exists($user, 'hasAnyAccount')) {
            return;
        }

        // Idempotency guard: never create a second account for a user who
        // already belongs to one.
        if ($user->hasAnyAccount()) {
            return;
        }

        $account = $this->accounts->create($user, $this->resolveAccountName($user));

        // Make the new account current. switchToAccount() validates membership
        // first (the user is the owner, so it passes) and persists
        // current_account_id, so login never has to resolve a current account.
        $user->switchToAccount($account);
    }

    /**
     * Render the account name from the configured template, substituting the
     * user's name. Falls back to a generic substitution when the name is
     * missing or blank, so a null/empty name never yields a broken name like
     * "'s Account".
     */
    private function resolveAccountName(Model $user): string
    {
        $template = config('jamesgifford.auth.accounts.default_name_template', "{name}'s Account");

        $name = $user->name;
        if (! is_string($name) || trim($name) === '') {
            $name = 'User';
        }

        return str_replace('{name}', $name, $template);
    }
}
