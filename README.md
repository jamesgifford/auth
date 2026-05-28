# JamesGifford Auth

[![Tests](https://github.com/jamesgifford/auth/actions/workflows/tests.yml/badge.svg)](https://github.com/jamesgifford/auth/actions/workflows/tests.yml)
[![Code Style](https://github.com/jamesgifford/auth/actions/workflows/code-style.yml/badge.svg)](https://github.com/jamesgifford/auth/actions/workflows/code-style.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20)](https://laravel.com/)
[![License](https://img.shields.io/badge/license-MIT-3DA639)](LICENSE)

A Laravel package providing prefixed public identifiers and multi-tenant account memberships with configurable roles and atomic invariant enforcement.

## Overview

Most applications eventually need the same two pieces of plumbing: stable public-facing identifiers that aren't auto-increment integers, and a way to group users into accounts with roles. This package provides both as reusable, well-tested scaffolding so each application doesn't reimplement them.

The **public ID subsystem** generates prefixed, URL-safe identifiers like `acc_k3p9m2x7q1w5n8r4t6zy`. The format — prefix separator, body length, alphabet, and optional checksum — is configurable, but once an application is set up the format is *locked*: a fingerprint of the format is written to a lock file, and any subsequent drift from that format is detected at boot and rejected. This matters because public IDs end up in URLs, external systems, and customer bookmarks; silently changing how they're generated would invalidate everything already issued. Locking the format makes that mistake impossible to make by accident.

The **accounts subsystem** models accounts, their members (through an explicit pivot carrying a role), and a strict single-owner invariant: every account has exactly one owner at all times, and the only way to change it is an atomic ownership transfer. All mutating operations run inside transactions and dispatch events only after commit. Those events carry immutable snapshots of the data as it was at the time of the operation, not live Eloquent models — so listeners and queued jobs see a stable, serializable record rather than a mutable reference that may have changed by the time they run.

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

## Quick Start

Run the installer:

```bash
php artisan jamesgifford:auth:install
```

This locks the public ID configuration, publishes and runs the migrations, seeds the default roles, and applies the `HasPublicId` and `HasAccounts` traits to your User model. It is interactive by default; pass `--force` for non-interactive (CI) use, or `--verify` to check an existing installation without changing anything.

A typical interaction with the package looks like this:

```php
use JamesGifford\Auth\Accounts\Services\AccountService;

$accounts = app(AccountService::class);

// Create an account; the given user becomes its owner
$account = $accounts->create($user, 'Acme Inc');

// Add a team member with the 'admin' role
$accounts->attachUser($account, $teammate, 'admin');

// Query membership directly on the User model
$user->isOwnerOf($account);        // true
$user->accounts;                    // every account the user belongs to
$user->switchToAccount($account);   // set the user's active account
```

## Public IDs

A public ID is composed of a prefix, a separator, a random body, and an optional checksum:

```
acc_k3p9m2x7q1w5n8r4t6zy
└┬┘ └────────┬─────────┘
prefix      body + checksum
```

The format is configurable (see [Configuration](#configuration)) — body length, alphabet, separator, and whether a checksum is appended. The checksum, when enabled, lets the validator detect transcription typos rather than just malformed input.

### The `HasPublicId` trait

Apply the trait to any Eloquent model and declare its prefix:

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

The trait generates `public_id` automatically on create, and overrides route-model binding so a public ID resolves the model out of the box:

```php
// GET /invoices/inv_k3p9m2x7q1w5n8r4t6zy resolves by public_id
Route::get('/invoices/{invoice}', fn (Invoice $invoice) => $invoice);

// Query scopes
Invoice::wherePublicId('inv_k3p9m2x7q1w5n8r4t6zy')->first();
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

PublicId::generate('usr');                 // 'usr_…'
PublicId::isValid($id);                    // true / false
PublicId::isValid($id, 'usr');             // also require the 'usr' prefix
PublicId::parse($id);                      // ValidationResult
PublicId::prefixOf($id);                   // 'usr', or null if invalid
PublicId::maxLength();                     // column size for migrations
```

### Validation rule

`ValidPublicId` plugs into Laravel's validator:

```php
use JamesGifford\Auth\PublicId\Rules\ValidPublicId;

$request->validate([
    'user_id'       => ['required', new ValidPublicId('usr')],
    'invitation_id' => ['nullable', ValidPublicId::withPrefix('inv')],
    'reference'     => ['required', new ValidPublicId()], // any prefix
]);
```

### Format locking

The format-defining settings are locked the first time you run `jamesgifford:public-id:setup` (the installer does this for you). Setup writes a fingerprint of the format to a lock file. On every subsequent boot, the package recomputes that fingerprint from the current config and compares it to the lock; if they diverge, boot fails with a clear error rather than quietly issuing IDs in an incompatible format. Changing a locked format requires an explicit, deliberately destructive reset.

### Commands

| Command | Description |
| --- | --- |
| `jamesgifford:public-id:setup` | Lock the public_id configuration for this application. |
| `jamesgifford:public-id:status` | Display the current public_id configuration status. |
| `jamesgifford:public-id:check` | Verify public_id prefix registry integrity and detect config issues. |
| `jamesgifford:public-id:reset` | Clear the public_id configuration lock. **Destructive:** invalidates all previously generated IDs. |

## Accounts

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
$user->roleIn($account);             // AccountRole|null
$user->hasRole($account, 'admin');   // bool
$user->hasAnyRole($account, ['admin', 'member']);
$user->isOwnerOf($account);          // bool
$user->isAdminOf($account);          // bool — true for owners as well as admins
$user->isFloating();                 // bool — true if the user has no memberships

// Switching the active account (throws if the user isn't a member)
$user->switchToAccount($account);
```

`isAdminOf()` deliberately returns `true` for owners as well as admins, reflecting the common authorization rule that owner privileges include admin privileges. `hasRole()`, by contrast, is an exact match — an owner does not "have" the admin role.

### `AccountService`

All account mutations go through the service. Every method runs in a database transaction and dispatches its event via `afterCommit`, so listeners never fire for work that was rolled back.

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

## Configuration

Configuration lives in `config/jamesgifford/auth.php` after publishing. The main areas are:

- **`public_id`** — prefix length, separator, body length and alphabet, and checksum settings. These format settings are locked after setup; only the per-model prefix map can change afterward.
- **`models`** — the User, Account, AccountRole, and AccountUser classes the package resolves, so you can point them at your own subclasses.
- **`roles`** — the roles seeded into the database; add custom roles here.
- **`accounts`** — account behavior, such as the default name template used when `create()` is called without a name.

## Testing

The package ships with a comprehensive suite of over 500 tests (527 at time of writing) covering both subsystems, the service layer's transaction and event behavior, the invariant enforcement, and the installer.

```bash
composer test
```

## License

Released under the [MIT License](LICENSE).

## Author

Built and maintained by James Gifford.
