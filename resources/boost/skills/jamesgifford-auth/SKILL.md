---
name: jamesgifford-auth
description: Use when working on authentication, public IDs, accounts, memberships, roles, account switching, or the setup/install commands in an application that uses the jamesgifford/auth package. Covers the HasPublicId/HasAccounts traits, AccountService, the account-switch HTTP routes, the EnsureCurrentAccount middleware, the JAMESGIFFORD_AUTH_* env vars, and the Artisan commands.
---

# JamesGifford Auth

## When to use this skill

Use when working on authentication, public IDs, accounts, memberships, roles,
account switching, or package setup in an app using the `jamesgifford/auth`
package. It gives multi-account ("team"/tenant) support keyed off prefixed
public IDs.

## Public IDs

Models expose stable, prefixed public IDs in the format
`{prefix}_{body}{checksum}` — e.g. `account_5n0p4kn48da58kdnzpkw` (prefix
`account`, an 18-char lowercase-alphanumeric body, and a 2-char checksum). Use
public IDs — never the integer `id` — in external URLs, route parameters, and
API responses. Route-model binding already resolves bound models by `public_id`
(the trait sets `getRouteKeyName()` to `public_id`).

Default prefixes (`config('jamesgifford.auth.public_id.prefixes')`):

- `App\Models\User` → `user`
- `JamesGifford\Auth\Models\Account` → `account`

Prefix resolution order (see `PrefixRegistry`): (1) the model's
`publicIdPrefix()` override, else (2) the `public_id.prefixes` config map, else
(3) throws `UnregisteredModelException`.

The public_id format is **locked at install** (via
`jamesgifford:public-id:setup`, which `jamesgifford:auth:install` runs) and
recorded in the lock file (default `config/jamesgifford/auth.lock.json`). Do NOT
change the format-defining `public_id` config keys afterward (`separator`,
`body.length`, `body.alphabet`, `checksum.*`): existing IDs would no longer
validate, and DB column sizes were set from the locked format. Only
`prefix_max_length` (default 7) and `prefixes` are safe to change post-lock —
and prefixes only for models that have no IDs yet.

Add public IDs to a new model with the `HasPublicId` trait plus a
`publicIdPrefix()` (or register it in the `prefixes` config map instead):

```php
use JamesGifford\Auth\PublicId\Concerns\HasPublicId;

class Invoice extends Model
{
    use HasPublicId;

    public function publicIdPrefix(): string
    {
        return 'inv'; // must be <= prefix_max_length (default 7)
    }
}
```

Query scopes from the trait: `Invoice::wherePublicId($id)` and
`Invoice::wherePublicIdIn($ids)`.

## Accounts and registration

Every user automatically gets a personal account they own, created at
registration by `CreateAccountOnRegistration`, a listener on Laravel's
`Illuminate\Auth\Events\Registered` event. Do NOT write account-creation code
for normal registration — it is automatic and idempotent (it skips users who
already belong to an account). The default name comes from
`config('jamesgifford.auth.accounts.default_name_template')` (`"{name}'s
Account"`).

Users can belong to multiple accounts; the `current_account_id` column on the
users table tracks the active one. The User model uses
`JamesGifford\Auth\Concerns\HasAccounts`.

## Membership and roles API

Query membership on the user (via `HasAccounts`):

```php
$user->accounts;                      // BelongsToMany — the user's accounts
$user->currentAccount;                // BelongsTo — the active account (nullable)
$user->ownedAccounts;                 // HasMany — accounts the user owns
$user->belongsToAccount($account);    // bool
$user->membershipIn($account);        // ?AccountUser (pivot model)
$user->roleIn($account);              // ?AccountRole
$user->hasRole($account, 'admin');    // bool — exact role key
$user->hasAnyRole($account, ['admin','member']); // bool
$user->isOwnerOf($account);           // bool
$user->isAdminOf($account);           // bool — TRUE for owners too
$user->hasAnyAccount();               // bool
$user->isFloating();                  // bool — authenticated but no current account
```

`isAdminOf()` returns true for both owners and admins, so write admin
authorization with it (don't separately OR-in `isOwnerOf`). Role keys are
`owner`, `admin`, `member`, `viewer` (configured in `jamesgifford.auth.roles`,
all `system => true`). Reference system roles via the `SystemRole` constants
(`SystemRole::OWNER`, `::ADMIN`, `::MEMBER`, `::VIEWER`) to avoid typos; use
raw strings for any consumer-added roles.

Mutate through `AccountService` (resolve from the container, e.g.
`app(\JamesGifford\Auth\Accounts\Services\AccountService::class)`). These are
the correct entry points — do NOT touch the `account_user` pivot directly. Each
dispatches an event (`AccountCreated`, `UserAttachedToAccount`, etc.):

```php
$service->create($owner, $name = null);                      // new account; $owner becomes owner
$service->attachUser($account, $user, $roleKey);             // add a member (non-owner role)
$service->detachUser($account, $user);                       // remove a member
$service->changeRole($account, $user, $newRoleKey);          // change a member's role
$service->transferOwnership($account, $newOwner, $previousOwnerNewRoleKey = SystemRole::ADMIN);
$service->delete($account);                                  // soft delete
$service->restore($account);                                 // restore soft-deleted
$service->forceDelete($account);                             // permanent delete
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
`{account}` is bound by public_id, behind the `auth` middleware):

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
and `redirect_missing_to` (current account gone); when null it auto-assigns a
sensible account instead of redirecting.

## Artisan commands

- `jamesgifford:auth:setup` — primary entry point. Sequences migrate → install
  → (optional dev data) → apply ID offsets. Flags: `--fresh` (migrate:fresh,
  refuses in production), `--with-dev-data` (seed local dev cast, refuses in
  production), `--force` (non-interactive; skips the educational pause).
- `jamesgifford:auth:install` — install/configure the package. Flags include
  `--fresh`, `--without-http`, `--publish-models`, `--skip-public-id`,
  `--skip-migrations`, `--skip-roles`, `--skip-user-model`, `--force`,
  `--verify`.
- `jamesgifford:auth:uninstall` — destructive removal (drops tables, deletes
  data). Flags: `--keep-config`, `--remove-published-models`,
  `--force-production`, `--force`.
- `jamesgifford:auth:seed-dev-data` — seed the deterministic local dev cast from
  `config/dev-data.php`. Fails closed: refuses in production and outside the
  configured `environments`. Run `apply-id-offsets` afterwards.
- `jamesgifford:auth:apply-id-offsets` — set the auto-increment start for the
  users/accounts tables (MySQL/Postgres; no-op on SQLite). Run after migrate and
  after any seeding.
- `jamesgifford:auth:publish-models` — publish editable `App\Models` subclasses.
- `jamesgifford:public-id:setup` / `:check` / `:status` / `:reset` — lock the
  public_id format / verify prefix integrity / show status / clear the lock
  (`:reset` is destructive — invalidates all existing IDs).

## Environment variables

All package env vars use the `JAMESGIFFORD_AUTH_` prefix and are read ONLY in
config files (don't call `env()` in app code):

- `JAMESGIFFORD_AUTH_DEV_PASSWORD` — shared password for seeded dev users
  (`config/dev-data.php`); hashed at seed time.
- `JAMESGIFFORD_AUTH_USERS_ID_OFFSET` — auto-increment start for the users table.
- `JAMESGIFFORD_AUTH_ACCOUNTS_ID_OFFSET` — auto-increment start for accounts.

## Customizing models

Models use attribute syntax: `#[Fillable([...])]` (and `#[Hidden([...])]` when
needed) on the class, not `protected $fillable`. A child class's `#[Fillable]`
OVERRIDES the parent's (most-derived wins, not merged).

To customize a model, publish editable subclasses with
`php artisan jamesgifford:auth:publish-models` (or `install --publish-models`).
It writes `App\Models\Account`, `App\Models\AccountUser`, and
`App\Models\AccountRole` extending the package base models, skipping any that
already exist. Then point `config('jamesgifford.auth.models.*')` at them (the
command prints the exact lines). Edit these published files — do NOT edit the
package's base models in `vendor/`.

## Do not

- Do NOT change the locked `public_id` format config after install.
- Do NOT write account-creation code for normal registration — it is automatic.
- Do NOT assign the `owner` role via `attachUser`/`changeRole` — use `transferOwnership`.
- Do NOT reference models by integer `id` in external URLs — use public IDs.
- Do NOT build account switching from scratch — use `switchToAccount()` or the switch route.
- Do NOT manipulate the `account_user` pivot directly — use `AccountService`.
- Do NOT call `env()` for `JAMESGIFFORD_AUTH_*` vars in app code — read config.
