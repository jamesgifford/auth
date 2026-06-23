---
name: jamesgifford-auth
description: Use when working on authentication, public IDs, accounts, memberships, roles, or account switching in an application that uses the jamesgifford/auth package. Covers the HasPublicId/HasAccounts traits, AccountService, the account-switch HTTP routes, and the EnsureCurrentAccount middleware.
---

# JamesGifford Auth

## When to use this skill

Use when working on authentication, public IDs, accounts, memberships, roles,
or account switching in an app using the `jamesgifford/auth` package. It gives
multi-account ("team"/tenant) support keyed off prefixed public IDs.

## Public IDs

Models expose stable, prefixed public IDs in the format
`{prefix}_{body}{checksum}` — e.g. `acc_5n0p4kn48da58kdnzpkw` (prefix `acc`,
an 18-char lowercase-alphanumeric body, and a 2-char checksum). Use public IDs
— never the integer `id` — in external URLs, route parameters, and API
responses. Route-model binding already resolves bound models by `public_id`.

The public_id format is **locked at install** and recorded in the lock file
(`config/jamesgifford/auth.lock.json`). Do NOT change the `public_id` config
keys (`jamesgifford.auth.public_id.*`: body length, alphabet, separator,
checksum) afterward: existing IDs would no longer validate, and DB column sizes
were set from the locked format.

Add public IDs to a new model with the `HasPublicId` trait plus a
`publicIdPrefix()`:

```php
use JamesGifford\Auth\PublicId\Concerns\HasPublicId;

class Invoice extends Model
{
    use HasPublicId;

    public function publicIdPrefix(): string
    {
        return 'inv';
    }
}
```

## Accounts and registration

Every user automatically gets a personal account they own, created at
registration by a listener on Laravel's `Illuminate\Auth\Events\Registered`
event. Do NOT write account-creation code for normal registration — it is
automatic and idempotent (it skips users who already belong to an account).

Users can belong to multiple accounts; the `current_account_id` column on the
users table tracks the active one. The User model uses
`JamesGifford\Auth\Concerns\HasAccounts`.

## Membership and roles API

Query membership on the user (via `HasAccounts`):

```php
$user->accounts;                      // BelongsToMany — the user's accounts
$user->currentAccount;                // BelongsTo — the active account (nullable)
$user->belongsToAccount($account);    // bool
$user->roleIn($account);              // ?AccountRole
$user->hasRole($account, 'admin');    // bool — exact role key
$user->isOwnerOf($account);           // bool
$user->isAdminOf($account);           // bool — TRUE for owners too
```

`isAdminOf()` returns true for both owners and admins, so write admin
authorization with it (don't separately OR-in `isOwnerOf`). Role keys are
`owner`, `admin`, `member`, `viewer` (configured in `jamesgifford.auth.roles`).

Mutate through `AccountService` (resolve from the container, e.g.
`app(\JamesGifford\Auth\Accounts\Services\AccountService::class)`). These are
the correct entry points — do NOT touch the `account_user` pivot directly:

```php
$service->create($owner, $name = null);                 // new account; $owner becomes owner
$service->attachUser($account, $user, $roleKey);        // add a member (non-owner role)
$service->detachUser($account, $user);                  // remove a member
$service->changeRole($account, $user, $newRoleKey);     // change a member's role
$service->transferOwnership($account, $newOwner);       // hand off ownership
$service->delete($account);                             // soft delete
$service->restore($account);                            // restore soft-deleted
$service->forceDelete($account);                        // permanent delete
```

Single-owner invariant: every account has exactly one owner. Change ownership
ONLY via `transferOwnership()`. `attachUser()` and `changeRole()` reject the
`owner` role on purpose — never assign ownership through them.

## Switching the current account

Backend primitive (validates membership, then sets + persists
`current_account_id`):

```php
$user->switchToAccount($account); // throws NotAMemberException if not a member
```

HTTP routes (registered when `jamesgifford.auth.http.enabled` is true;
`{account}` is bound by public_id):

- `POST /account/switch/{account}` — route name `jamesgifford-auth.account.switch`.
  Returns a redirect (web) or JSON (API). Never a view.
- `GET /account/list` — route name `jamesgifford-auth.account.list`. Returns the
  user's accounts as JSON (`public_id`, `name`, `is_current`) for any frontend.

To build a switcher UI: iterate `$user->accounts`, POST to
`jamesgifford-auth.account.switch` with each account's public_id, and mark
`$user->currentAccount` as the active one.

Apply the `auth.current-account` middleware alias (the `EnsureCurrentAccount`
middleware) to routes that require an active account. Its redirect
destinations are config route names:
`jamesgifford.auth.http.middleware.redirect_floating_to` (no current account)
and `jamesgifford.auth.http.middleware.redirect_missing_to` (current account
gone); when null it auto-assigns a sensible account instead of redirecting.

## Customizing models

Models use Laravel 13 attribute syntax: `#[Fillable([...])]` (and `#[Hidden([...])]`
when needed) on the class, not `protected $fillable`. A child class's
`#[Fillable]` OVERRIDES the parent's (most-derived wins, not merged).

To customize a model, publish editable subclasses with
`php artisan jamesgifford:auth:publish-models`. It writes `App\Models\Account`,
`App\Models\AccountUser`, and `App\Models\AccountRole` extending the package base
models (with their `#[Fillable]`, prefix, and casts written out), skipping any
that already exist. Then point `config('jamesgifford.auth.models.*')` at them
(the command prints the exact lines). The `account` model is consulted
throughout the package; `account_user`/`account_role` are provided mainly for
your own customization. Edit these published files — do NOT edit the package's
base models in `vendor/`.

## Do not

- Do NOT change the locked `public_id` config after install.
- Do NOT write account-creation code for normal registration — it is automatic.
- Do NOT assign the `owner` role via `attachUser`/`changeRole` — use `transferOwnership`.
- Do NOT reference models by integer `id` in external URLs — use public IDs.
- Do NOT build account switching from scratch — use `switchToAccount()` or the switch route.
- Do NOT manipulate the `account_user` pivot directly — use `AccountService`.
