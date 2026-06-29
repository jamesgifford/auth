# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-06-28

First stable release. This entry summarizes the package's full capabilities; the
accounts subsystem, setup orchestration, HTTP plumbing, and tooling below are new
since 0.1.0, which shipped only the public ID subsystem.

### Added

#### Public IDs
- Default prefixes `user` (`App\Models\User`) and `account` (the package `Account` model), resolved via `publicIdPrefix()` override then the config `prefixes` map.
- `PublicId` facade (`generate`, `isValid`, `validate`, `parse`, `prefixOf`, `maxLength`) and the `ValidPublicId` rule. (Subsystem introduced in 0.1.0; see that entry for the full primitives.)

#### Accounts, memberships, and roles
- `Account`, `AccountUser` (pivot carrying role and join time), and `AccountRole` models; accounts are soft-deletable.
- Single-owner invariant: every account has exactly one owner, enforced and reconciled with the `owner_id` column.
- `HasAccounts` trait for the User model: `accounts`, `memberships`, `currentAccount`, `ownedAccounts` relationships plus `belongsToAccount`, `membershipIn`, `roleIn`, `hasRole`, `hasAnyRole`, `isOwnerOf`, `isAdminOf`, `hasAnyAccount`, `isFloating`, and `switchToAccount`.
- `AccountService` for all mutations (`create`, `attachUser`, `detachUser`, `changeRole`, `transferOwnership`, `delete`, `restore`, `forceDelete`), each transactional and dispatching its event after commit.
- `AccountIntegrityService` for read-only auditing of owner-invariant violations.
- Default system roles `owner`, `admin`, `member`, `viewer` (seeded from config; the `owner` role is protected) with `SystemRole` constants.
- Domain events carrying immutable snapshots: `AccountCreated`, `UserAttachedToAccount`, `UserDetachedFromAccount`, `AccountRoleChanged`, `AccountOwnershipTransferred`, `AccountDeleted`, `AccountRestored`, `AccountForceDeleted`.

#### Registration
- `CreateAccountOnRegistration` listener auto-creates a personal account the new user owns, on Laravel's `Registered` event (idempotent — skips users who already belong to an account).

#### Setup, install, and uninstall
- `jamesgifford:auth:setup`: one-command orchestration (migrate, install, optionally seed dev data, apply ID offsets); interactive locally, non-interactive in production via `--force`; `--fresh` and `--with-dev-data` for development.
- `jamesgifford:auth:install`: locks the public_id format, publishes and runs migrations, seeds roles, and surgically modifies the User model (with `--without-http`, `--publish-models`, `--verify`, and skip flags).
- `jamesgifford:auth:uninstall`: rolls back migrations, reverts the User-model modifications, and removes the published config, migration files, and lock file.

#### HTTP plumbing (frontend-agnostic)
- Account switch route (`POST /account/switch/{account}`, `jamesgifford-auth.account.switch`) and list endpoint (`GET /account/list`, `jamesgifford-auth.account.list`) that redirect or return JSON — never views.
- `EnsureCurrentAccount` middleware (alias `auth.current-account`) with configurable redirect targets; all gated by `http.enabled`.

#### Development tooling
- `jamesgifford:auth:seed-dev-data`: deterministic local dev cast, restricted to configured environments (default `local`/`staging`, always refused in production) with an env-sourced, hashed password (`JAMESGIFFORD_AUTH_DEV_PASSWORD`).
- `jamesgifford:auth:apply-id-offsets`: configurable auto-increment starting values for the users and accounts tables (MySQL/MariaDB and PostgreSQL; no-op on SQLite).
- `jamesgifford:auth:publish-models`: publishes editable `App\Models` subclasses (`Account`, `AccountUser`, `AccountRole`).
- Laravel Boost skill (`resources/boost/skills/jamesgifford-auth/`) documenting the package's API and guardrails for AI tooling.

## [0.1.0] - 2026-05-06

Initial release. Public ID subsystem.

### Added

- Configurable public_id format with prefix, body, optional checksum, and separator.
- Built-in alphabet presets: `lowercase_alpha`, `lowercase_alphanumeric`, `uppercase_alpha`, `uppercase_alphanumeric`, `mixed_alphanumeric`, `crockford`, `nolookalikes`. Custom presets supported via configuration.
- `PositionalSumChecksum` strategy (default) and `NullChecksum` for disabled-checksum configurations. Custom strategies can be implemented via the `ChecksumStrategy` interface.
- `JamesGifford\Auth\PublicId\PublicId` static facade for generation, validation, and length helpers.
- `JamesGifford\Auth\PublicId\Concerns\HasPublicId` Eloquent trait providing auto-generation, route-model binding by public_id, and `wherePublicId` / `wherePublicIdIn` query scopes.
- `JamesGifford\Auth\PublicId\PrefixRegistry` for model-to-prefix resolution with collision detection.
- `JamesGifford\Auth\PublicId\Rules\ValidPublicId` validation rule for FormRequests and inline validation.
- Configuration locking via `config/jamesgifford/auth.lock.json`. Boot-time guard throws on configuration drift.
- Console commands: `jamesgifford:public-id:setup`, `jamesgifford:public-id:status`, `jamesgifford:public-id:check`, `jamesgifford:public-id:reset`.
- Configuration published to `config/jamesgifford/auth.php` with `vendor:publish --tag=jamesgifford-auth-config`.

### Requirements

- PHP 8.3+
- Laravel 12 or 13

[Unreleased]: https://github.com/jamesgifford/auth/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/jamesgifford/auth/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/jamesgifford/auth/releases/tag/v0.1.0
