# JamesGifford Auth

[![Tests](https://github.com/jamesgifford/auth/actions/workflows/tests.yml/badge.svg)](https://github.com/jamesgifford/auth/actions/workflows/tests.yml)
[![Code Style](https://github.com/jamesgifford/auth/actions/workflows/code-style.yml/badge.svg)](https://github.com/jamesgifford/auth/actions/workflows/code-style.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20)](https://laravel.com/)
[![License](https://img.shields.io/badge/license-MIT-3DA639)](LICENSE)

Reusable Laravel authentication scaffolding: prefixed public identifiers and multi-account memberships with configurable roles and enforced invariants.

## Overview

Most applications eventually need the same two pieces of plumbing: stable public-facing identifiers that aren't auto-increment integers, and a way to group users into accounts with roles. This package provides both as reusable, tested scaffolding so each application doesn't reimplement them, and ships a single setup command that wires the whole thing into a fresh app.

The **public ID subsystem** generates prefixed, URL-safe identifiers like `account_5n0p4kn48da58kdnzpkw`. The format — separator, body length, alphabet, and optional checksum — is configurable, but once an application is set up the format is *locked*: a fingerprint of the format is written to a lock file, and any subsequent drift from it is detected at boot and rejected. Public IDs end up in URLs, external systems, and customer bookmarks; silently changing how they're generated would invalidate everything already issued. Locking the format makes that mistake impossible to make by accident.

The **accounts subsystem** models accounts, their members (through an explicit pivot carrying a role), and a strict single-owner invariant: every account has exactly one owner at all times, and the only way to change it is an atomic ownership transfer. All mutating operations run inside transactions and dispatch events only after commit. Those events carry immutable snapshots of the data as it was at the time of the operation, not live Eloquent models — so listeners and queued jobs see a stable, serializable record rather than a mutable reference that may have changed by the time they run. Registration auto-creates a personal account, so every user has somewhere to land without bespoke wiring.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

This package is distributed via its Git repository rather than Packagist. Add the repository to your application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/jamesgifford/auth.git"
        }
    ]
}
```

Then require the package:

```bash
composer require jamesgifford/auth
```

### Setup

`jamesgifford:auth:setup` is the primary entry point. It sequences the whole setup in the correct order: migrate (or `migrate:fresh`), install (lock the public_id format, publish and run migrations, seed roles, modify the User model), optionally seed local dev data, and apply ID offsets.

```bash
# Interactive local setup (pauses to explain the irreversible public_id lock)
php artisan jamesgifford:auth:setup

# Local: start from a clean database and seed the dev cast
php artisan jamesgifford:auth:setup --fresh --with-dev-data

# Non-interactive (CI / production): skip the educational pause, propagate --force
php artisan jamesgifford:auth:setup --force
```

| Flag | Effect |
| --- | --- |
| `--fresh` | Reset the database with `migrate:fresh` first. Development only — the command refuses in production. |
| `--with-dev-data` | Also seed the deterministic local dev cast. The seeder refuses in production even with this flag. |
| `--force` | Run non-interactively: skip the educational pause and propagate `--force` to the migrate step. |

The interactive flow pauses before the irreversible public_id lock to surface the format that's about to be locked. In production you run it non-interactively with `--force`; `--fresh` and `--with-dev-data` are refused there regardless.

If you prefer to run the steps yourself, `jamesgifford:auth:install` performs just the install stage. See [Commands](#commands) for the full list.

## Quick start

Registration auto-creates a personal account the user owns, so a freshly registered user is immediately a member of one account. From there, the typical interaction looks like this:

```php
use JamesGifford\Auth\Accounts\Services\AccountService;

$accounts = app(AccountService::class);

// Create another account; the given user becomes its owner
$account = $accounts->create($user, 'Acme Inc');

// Add a team member with the 'admin' role
$accounts->attachUser($account, $teammate, 'admin');

// Query membership directly on the User model (via the HasAccounts trait)
$user->isOwnerOf($account);         // true
$user->accounts;                    // every account the user belongs to
$user->currentAccount;              // the user's active account
$user->switchToAccount($account);   // set the user's active account
```

## Public IDs

A public ID is composed of a prefix, a separator, a random body, and an optional checksum:

```
account_5n0p4kn48da58kdnzpkw
└──┬──┘ └────────┬─────────┘
 prefix      body + checksum
```

The default prefixes are `user` (for `App\Models\User`) and `account` (for the package's `Account` model). The format is configurable (see [Configuration](#configuration)) — body length, alphabet, separator, and whether a checksum is appended. The checksum, when enabled, lets the validator detect transcription typos rather than just malformed input.

### The `HasPublicId` trait

Apply the trait to any Eloquent model and declare its prefix:

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

The prefix may instead be registered in the `public_id.prefixes` config map. Resolution order is: the model's `publicIdPrefix()` override, then the config map, otherwise an `UnregisteredModelException`.

The trait generates `public_id` automatically on create, and overrides route-model binding so a public ID resolves the model out of the box:

```php
// GET /invoices/inv_5n0p4kn48da58kdnzpkw resolves by public_id
Route::get('/invoices/{invoice}', fn (Invoice $invoice) => $invoice);

// Query scopes
Invoice::wherePublicId('inv_5n0p4kn48da58kdnzpkw')->first();
Invoice::wherePublicIdIn([$idA, $idB])->get();
```

The model's table needs a `public_id` column sized with `PublicId::maxLength()`:

```php
$table->string('public_id', PublicId::maxLength())->unique();
```

### The `PublicId` facade

A static entry point for generation and validation:

```php
use JamesGifford\Auth\PublicId\PublicId;

PublicId::generate('user');                 // 'user_…'
PublicId::isValid($id);                     // true / false
PublicId::isValid($id, 'user');             // also require the 'user' prefix
PublicId::validate($id);                    // ValidationResult
PublicId::parse($id);                       // ValidationResult
PublicId::prefixOf($id);                    // 'user', or null if invalid
PublicId::maxLength();                      // column size for migrations
```

### Validation rule

`ValidPublicId` plugs into Laravel's validator:

```php
use JamesGifford\Auth\PublicId\Rules\ValidPublicId;

$request->validate([
    'user_id'    => ['required', new ValidPublicId('user')],
    'account_id' => ['nullable', ValidPublicId::withPrefix('account')],
    'reference'  => ['required', new ValidPublicId()], // any prefix
]);
```

### Format locking

The format-defining settings are locked the first time you run `jamesgifford:public-id:setup` (the installer does this for you). Setup writes a fingerprint of the format to a lock file (default `config/jamesgifford/auth.lock.json`). On every subsequent boot, the package recomputes that fingerprint from the current config and compares it to the lock; if they diverge, boot fails with a clear error rather than quietly issuing IDs in an incompatible format. Only `prefix_max_length` and the per-model `prefixes` map are safe to change after lock — and a prefix only for a model that has no IDs yet. Changing a locked format requires an explicit, deliberately destructive reset.

### Commands

| Command | Description |
| --- | --- |
| `jamesgifford:public-id:setup` | Lock the public_id configuration for this application. |
| `jamesgifford:public-id:status` | Display the current public_id configuration status. |
| `jamesgifford:public-id:check` | Verify public_id prefix registry integrity and detect config issues. |
| `jamesgifford:public-id:reset` | Clear the public_id configuration lock. **Destructive:** invalidates all previously generated IDs. |

## Accounts and memberships

An account has an owner and zero or more members. Membership is stored in an explicit pivot (`account_user`) that records the member's role and when they joined. Roles are reference data seeded from config. Accounts are soft-deletable so membership history survives a deletion.

### The `HasAccounts` trait

Applied to your User model, it adds relationships, role checks, and account switching:

```php
// Relationships
$user->accounts;          // BelongsToMany — accounts the user is a member of
$user->memberships;       // HasMany — the pivot rows directly
$user->currentAccount;    // BelongsTo — the user's active account (nullable)
$user->ownedAccounts;     // HasMany — accounts the user owns

// Membership & role checks (scoped to a given account)
$user->belongsToAccount($account);   // bool
$user->membershipIn($account);       // AccountUser|null
$user->roleIn($account);             // AccountRole|null
$user->hasRole($account, 'admin');   // bool — exact match
$user->hasAnyRole($account, ['admin', 'member']);
$user->isOwnerOf($account);          // bool
$user->isAdminOf($account);          // bool — true for owners as well as admins
$user->hasAnyAccount();              // bool
$user->isFloating();                 // bool — authenticated but no current account

// Switching the active account (throws NotAMemberException if not a member)
$user->switchToAccount($account);
```

`isAdminOf()` deliberately returns `true` for owners as well as admins, reflecting the common authorization rule that owner privileges include admin privileges. `hasRole()`, by contrast, is an exact match — an owner does not "have" the admin role.

### Registration auto-creates an account

A listener (`CreateAccountOnRegistration`) on Laravel's `Illuminate\Auth\Events\Registered` event creates a personal account the new user owns. It's idempotent — it skips users who already belong to an account — so you don't write account-creation code for normal registration. The default name comes from `accounts.default_name_template` (`"{name}'s Account"`).

### `AccountService`

All account mutations go through the service. Every method runs in a database transaction and dispatches its event via `afterCommit`, so listeners never fire for work that was rolled back. Don't touch the `account_user` pivot directly.

| Method | Purpose |
| --- | --- |
| `create(Model $owner, ?string $name = null)` | Create an account and seed the owner's membership. Falls back to a configurable default name. |
| `attachUser(Account $account, Model $user, string $roleKey)` | Add a member with the given role. |
| `detachUser(Account $account, Model $user)` | Remove a member; clears their `current_account_id` if it pointed here. |
| `changeRole(Account $account, Model $user, string $newRoleKey)` | Change a member's role. |
| `transferOwnership(Account $account, Model $newOwner, string $previousOwnerNewRoleKey = SystemRole::ADMIN)` | Atomically hand ownership to another existing member. |
| `delete(Account $account)` | Soft-delete the account. |
| `restore(Account $account)` | Restore a soft-deleted account. |
| `forceDelete(Account $account)` | Permanently delete the account and cascade its memberships. |

`attachUser` and `changeRole` refuse to assign the `owner` role — ownership is managed only through `create` and `transferOwnership`. Likewise `detachUser` refuses to remove the owner. These guards keep the single-owner invariant intact.

### Roles

Four roles ship by default and are referenced through `SystemRole` constants:

```php
use JamesGifford\Auth\SystemRole;

SystemRole::OWNER;   // 'owner'
SystemRole::ADMIN;   // 'admin'
SystemRole::MEMBER;  // 'member'
SystemRole::VIEWER;  // 'viewer'
```

Roles are configurable — add your own in `config/jamesgifford/auth.php` and re-run the seeder. The `owner` role is required and protected: it cannot be deleted, because the account model depends on it.

### The single-owner invariant

Every account has exactly one owner at all times. The owner is both a column on the account (`owner_id`) and a membership row with the `owner` role, and the two are kept in sync. Ownership cannot be reassigned by editing a role or detaching a user — the only path is `transferOwnership`, which demotes the previous owner (to admin by default), promotes the new owner, and updates the account in a single transaction. No intermediate "two owners" or "zero owners" state is ever observable.

### Events

Each operation dispatches an event after its transaction commits:

| Event | Dispatched by |
| --- | --- |
| `AccountCreated` | `create` |
| `UserAttachedToAccount` | `attachUser` |
| `UserDetachedFromAccount` | `detachUser` |
| `AccountRoleChanged` | `changeRole` |
| `AccountOwnershipTransferred` | `transferOwnership` |
| `AccountDeleted` | `delete` |
| `AccountRestored` | `restore` |
| `AccountForceDeleted` | `forceDelete` |

Events carry immutable snapshots (`Transfer` objects), not live models:

```php
use Illuminate\Support\Facades\Event;
use JamesGifford\Auth\Events\AccountCreated;

Event::listen(function (AccountCreated $event) {
    $event->account->publicId;  // AccountTransfer — a snapshot
    $event->owner->email;       // UserTransfer
});
```

### `AccountIntegrityService`

A read-only scanner that detects accounts violating the owner invariant — no owner membership, multiple owner memberships, or an `owner_id` that disagrees with the owner-role member. Useful for auditing data that may have been modified outside the service layer.

```php
use JamesGifford\Auth\Accounts\Services\AccountIntegrityService;

$issues = app(AccountIntegrityService::class)->scan(); // Collection of issues
```

## Account switching and HTTP

The package ships a frontend-agnostic HTTP layer: the controllers only redirect or return JSON — never a view — so it works identically on Livewire, Inertia, Blade, or API stacks. The routes and the middleware alias are registered only when `http.enabled` is `true` (run `install --without-http` to disable). `{account}` is resolved by `public_id`.

| Method & path | Route name | Behavior |
| --- | --- | --- |
| `POST /account/switch/{account}` | `jamesgifford-auth.account.switch` | Switch the current account; redirect (web) or JSON (API). |
| `GET /account/list` | `jamesgifford-auth.account.list` | The user's accounts as JSON (`public_id`, `name`, `is_current`). |

The backend primitive is `$user->switchToAccount($account)`, which validates membership, then sets and persists `current_account_id` (throwing `NotAMemberException` if the user isn't a member). The HTTP switch route is a thin wrapper over it.

Apply the `auth.current-account` middleware alias (the `EnsureCurrentAccount` middleware) to routes that require an active account. Its redirect destinations are config route names: `http.middleware.redirect_floating_to` (no current account) and `redirect_missing_to` (current account gone). When either is `null`, the middleware auto-assigns a sensible account and continues instead of redirecting.

## Configuration

Configuration lives in `config/jamesgifford/auth.php` after publishing. The main areas are:

- **`public_id`** — `prefix_max_length`, separator, body length and alphabet, checksum settings, the lock file path, and the per-model `prefixes` map. The format settings are locked after setup; only `prefix_max_length` and `prefixes` can change afterward.
- **`models`** — the User, Account, AccountRole, and AccountUser classes the package resolves, so you can point them at your own subclasses.
- **`roles`** — the roles seeded into the database; add custom roles here.
- **`accounts`** — account behavior, such as the default name template used when `create()` is called without a name.
- **`id_offsets`** — optional auto-increment starting values for the users and accounts tables (see [Development](#development)).
- **`http`** — `enabled` plus the `EnsureCurrentAccount` redirect targets.

### Environment variables

All package env vars use the `JAMESGIFFORD_AUTH_` prefix and are read only in config files (don't call `env()` in application code):

| Variable | Used by |
| --- | --- |
| `JAMESGIFFORD_AUTH_DEV_PASSWORD` | Shared password for seeded dev users (`config/dev-data.php`); hashed at seed time. |
| `JAMESGIFFORD_AUTH_USERS_ID_OFFSET` | Auto-increment start for the users table. |
| `JAMESGIFFORD_AUTH_ACCOUNTS_ID_OFFSET` | Auto-increment start for the accounts table. |

## Development

### Dev-data seeding

`jamesgifford:auth:seed-dev-data` seeds a deterministic local cast (owners, admins, members, a multi-account user, and a floating user) defined in `config/dev-data.php`. It **fails closed**: it refuses in production and outside the configured `environments` (default `local`, `staging`). The shared password is sourced from `JAMESGIFFORD_AUTH_DEV_PASSWORD` and hashed at seed time — no credential is committed. The cast ships pre-populated, so a fresh install is immediately seedable (or via `setup --with-dev-data`).

### ID offsets

`jamesgifford:auth:apply-id-offsets` sets the auto-increment starting values for the users and accounts tables (from the `id_offsets` config / env vars above), so real records begin above a chosen number and low IDs stay reserved for deterministic dev fixtures. Run it after migrating and after any seeding. Supported on MySQL/MariaDB and PostgreSQL; a no-op on SQLite. `setup` runs this as its final step.

## AI tooling

The package ships a [Laravel Boost](https://github.com/laravel/boost) skill (`resources/boost/skills/jamesgifford-auth/`) that teaches an AI assistant the package's public API and guardrails — public IDs, the account/role model, switching, and the setup commands. In a consuming app that uses Boost, install it with `php artisan boost:install` (or `boost:update` to refresh).

## Uninstall

`jamesgifford:auth:uninstall` removes the package's footprint: it drops the package tables, reverts the User model modifications, removes the package config, and clears the public_id lock. It is destructive (drops tables and deletes data) and prompts before proceeding.

```bash
php artisan jamesgifford:auth:uninstall
```

| Flag | Effect |
| --- | --- |
| `--keep-config` | Keep the published config file instead of deleting it. |
| `--remove-published-models` | Also delete the published `App\Models` subclasses (interactive runs prompt instead). |
| `--force` | Skip the confirmation prompt (non-interactive use). |
| `--force-production` | Permit the uninstall to run in a production environment. |

## Commands

| Command | Description |
| --- | --- |
| `jamesgifford:auth:setup` | Primary entry point: migrate, install, optionally seed dev data, apply ID offsets. |
| `jamesgifford:auth:install` | Install/configure the package (lock public_id, migrate, seed roles, modify User model). |
| `jamesgifford:auth:uninstall` | Remove the package's footprint. Destructive. |
| `jamesgifford:auth:seed-dev-data` | Seed the deterministic local dev cast (local/staging only). |
| `jamesgifford:auth:apply-id-offsets` | Apply auto-increment ID offsets to the users and accounts tables. |
| `jamesgifford:auth:publish-models` | Publish editable `App\Models` subclasses (Account, AccountUser, AccountRole). |
| `jamesgifford:public-id:setup` | Lock the public_id configuration. |
| `jamesgifford:public-id:status` | Display the current public_id configuration status. |
| `jamesgifford:public-id:check` | Verify prefix registry integrity and detect config issues. |
| `jamesgifford:public-id:reset` | Clear the public_id lock. Destructive. |

## Testing

The package ships with a suite of 628 tests covering both subsystems, the service layer's transaction and event behavior, the invariant enforcement, the HTTP layer, and the installer.

```bash
composer test
```

## License

Released under the [MIT License](LICENSE).

## Author

Built and maintained by James Gifford.
