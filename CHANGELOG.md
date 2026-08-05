# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-08-05

### Added
- `PackageModels` resolver (`JamesGifford\Auth\PackageModels`) as the single source of truth for the model classes the package operates on. **All four `models.*` config overrides are now honored everywhere** — services, trait relationships, factories, seeders, the installer, and the HTTP surface. Previously `models.account_role` was read nowhere and `models.account_user` almost nowhere, so pointing them at published subclasses silently did nothing.
- Explicit `{account}` route binder registered by the service provider (when `http.enabled`), bound to the configured `models.account` class and resolving by `public_id`. Route-model binding now returns the consumer's subclass. Note the binder applies by parameter name application-wide; consumer routes using `{account}` resolve through it too.
- PHPStan (Larastan) at level 6 with zero baseline; `composer analyse` and a single `composer check` script (Pint + PHPStan + PHPUnit); a PHPStan job in CI.
- Drift-guard tests: every `jamesgifford:*` command name in src string literals must be a registered command; package code must resolve models through `PackageModels`; `models.*` override behavior pinned across relationships, services, factories, and route binding.
- `jamesgifford:auth:setup` now ends with a next-steps block: DatabaseSeeder wiring for `AccountRoleSeeder`/`DevDataSeeder` (previously README-only) and the Boost `boost:update`/`boost:install` reminder (previously printed mid-run by install, where it scrolled away).
- README documents the `jamesgifford:auth:install` flags and the internal `jamesgifford-auth-migrations` publish tag; the Boost skill documents that `models.*` overrides are honored everywhere via `PackageModels`.

### Fixed
- `jamesgifford:public-id:check` no longer reports a false prefix collision after the standard `--publish-models` flow: `PrefixRegistry` now treats same-prefix claims within one inheritance chain (package base model + published subclass) as a single logical registration; `modelFor()` resolves to the most-derived registered class. Genuine collisions (unrelated classes) still throw.
- Relationship foreign keys involving config-resolved classes are pinned explicitly, so a consumer subclass named e.g. `CustomAccount` no longer makes Eloquent guess wrong column names (`custom_account_id`).
- Stale user-facing strings: install's `--fresh` abort no longer claims uninstall is "not yet available"; `OwnerlessAccountException` no longer references the nonexistent `check-integrity` command; the uninstall docblock, Boost skill lock-mechanism wording, `composer.json` description ("invitations" removed), README test count, `public-id:reset` docs (required flag), and `usr`-vs-`user` docblock examples all corrected.

### Security
- Updated the dev-dependency `guzzlehttp/guzzle` to 7.15.2, clearing all open advisories (dev-only; not a runtime dependency of the package).

## [1.1.4] - 2026-07-18

### Fixed
- The `users.public_id` migration now works against a **populated** users table: the column is added nullable, existing rows are backfilled with generated public IDs (prefix resolved through the registry with a `'user'` fallback), and only then is the column made NOT NULL + unique. Previously a single ALTER failed on SQLite and violated the unique index on MySQL.
- Each step of that migration is individually re-runnable (`hasColumn` / unique-index guards), so a partial failure under MySQL's non-transactional DDL can be resumed instead of dying on "Duplicate column name".
- The backfill runs in a single transaction (one commit instead of one per row) and short-circuits when nothing needs backfilling.
- `DevDataSeeder` assigns an explicit public_id to leftover users whose public_id is null (the trait only generates on `creating`, never on update).
- The `setup --with-dev-data` pre-flight now errors on a malformed offset (non-integer or < 1) instead of letting it fail at the final apply step after migrate + seed already ran, and validates against **effective** record counts (users deduplicated by email; accounts counted only when named with a resolvable owner) rather than raw declaration counts.

### Changed
- `DevDataSeeder` resolves the `public_id` column check once per run instead of issuing a schema-introspection query per declared user.

## [1.1.3] - 2026-07-18

### Added
- `jamesgifford:auth:install --skip-id-offsets` flag.
- Pre-flight validation in `setup --with-dev-data`: a configured ID offset must exceed the number of dev records for its table. Runs before Step 1 (nothing migrated or seeded on failure) and only when the seeder's environment guard would allow seeding.

### Fixed
- Setup ordering: ID offsets are applied exactly once, as setup's final step **after** dev-data seeding. Previously install applied them at Step 2, pushing the dev fixtures up to the offset instead of the low IDs.

### Changed
- `IdOffsetManager::normalizeOffset()` is now `public static` so callers (the setup pre-flight) reason about the exact value the manager will act on.

## [1.1.2] - 2026-07-04

### Changed (breaking)
- Dev-data config renamed: `config/dev-data.php` → `config/auth-dev.php`, published path `config/jamesgifford/dev-data.php` → `config/jamesgifford/auth-dev.php`, config key `jamesgifford.dev-data.*` → `jamesgifford.auth-dev.*`, publish tag `jamesgifford-auth-dev-data` → `jamesgifford-auth-dev`.
- Dev-data cast schema restructured: accounts are declared once in a top-level `accounts` list (`name` + `owner` email), each user declares its own `memberships` (account + role) and optional `current_account`. Account idempotency is by name alone. Anyone who customized the old file must migrate it to the new shape.

### Added
- `DevDataSeeder` is now a first-class Laravel seeder (`extends Seeder`): register it in a consuming app's `DatabaseSeeder` with `$this->call(DevDataSeeder::class)`. Its `run()` carries a non-throwing environment guard (logs and skips outside allowed environments; production always refused), so `migrate:fresh --seed` is safe everywhere; the throwing guard remains for the console command. New public `environmentAllowed(): bool`.
- Seeding validates `current_account` against actual ownership/membership, so a typo can never point a user at an inaccessible account.

### Fixed
- Uninstall now removes the (renamed) published dev-data config file.

## [1.1.1] - 2026-07-03

### Changed
- Migration publishing now assigns **fresh timestamps** at publish time. `jamesgifford:auth:install` copies the package's migrations into `database/migrations/` with new timestamp prefixes generated at that moment (sequential, in dependency order), instead of the package's frozen source timestamps. This guarantees they sort after the app's system migrations and before project migrations added later — fixing an ordering failure where a project migration with an `account_id` foreign key could sort before the package's `accounts` migration and fail under `migrate:fresh` / `setup --fresh`.
- The package's own migrations are identified by their descriptive **stem** (the part after the `YYYY_MM_DD_HHMMSS_` prefix) rather than exact filenames, so `--fresh` and `uninstall` still find and remove the published copies despite their now-variable timestamps. Each published file also carries a comment noting it was published by the package.

### Upgrade note
- Apps installed with a previous version recorded the migrations under the package's original frozen filenames. Because publishing now emits fresh filenames, an in-place upgrade would make Laravel see them as un-run and fail (the tables already exist). Reconcile with a **fresh setup**: `php artisan jamesgifford:auth:setup --fresh` (or `migrate:fresh` followed by `jamesgifford:auth:install`). This is a development-rebuild workflow; no automatic migration-record reconciliation is attempted.

## [1.1.0] - 2026-06-28

Identical to 1.0.0 — a duplicate tag of the same commit; no changes. (This entry
exists so the changelog versions line up with the published git tags.)

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

### Changed

- Narrowed the supported versions to PHP 8.4+ (developed on 8.5) and Laravel 13 only, dropping the earlier PHP and Laravel floors. The models use Laravel 13's `#[Fillable]` / `#[Hidden]` Eloquent attributes, which earlier Laravel does not honor.

### Requirements

- PHP 8.4+ (developed on PHP 8.5)
- Laravel 13

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

[Unreleased]: https://github.com/jamesgifford/auth/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/jamesgifford/auth/compare/v1.1.4...v1.2.0
[1.1.4]: https://github.com/jamesgifford/auth/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/jamesgifford/auth/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/jamesgifford/auth/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/jamesgifford/auth/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/jamesgifford/auth/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/jamesgifford/auth/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/jamesgifford/auth/releases/tag/v0.1.0
