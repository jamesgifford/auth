# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.3] - 2026-08-11

### Added
- `jamesgifford:auth:install` now wires `AccountRoleSeeder` and the new `ApplyIdOffsetsSeeder` into `database/seeders/DatabaseSeeder.php`, and `jamesgifford:auth:setup --with-dev-data` adds `DevDataSeeder`. After setup, `php artisan migrate:refresh --seed` (or `migrate:fresh --seed`) rebuilds roles, dev fixtures, and ID offsets with no manual file edits. Note that `migrate:refresh` alone re-runs migrations without seeding — the `--seed` flag is Laravel's, and the wiring is what makes it do the right thing.
- New `ApplyIdOffsetsSeeder`. A `--seed` rebuild resets each table's auto-increment counter, so offsets applied at setup time were previously lost until `jamesgifford:auth:apply-id-offsets` was re-run by hand. It is a no-op when no offsets are configured and on SQLite. Offsets are a convenience rather than a correctness requirement, so any failure — a malformed offset, or a driver refusing the `ALTER` (insufficient grants, a locked table) — is logged and skipped rather than allowed to abort the seeding run.
- New `--skip-seeder-wiring` flag on `install` and `setup` for consumers who manage `DatabaseSeeder` themselves. `install --verify` reports the wiring state unless the flag is passed; a `DatabaseSeeder` that exists but cannot be parsed is surfaced as an advisory line rather than a failed check, so an unrelated syntax error in that file cannot fail an otherwise-complete install.

### Changed
- `jamesgifford:auth:uninstall` removes the package's `$this->call(...)` lines from `DatabaseSeeder`, preserving the application's own seeders, and names the edit in its up-front teardown summary.
- `jamesgifford:auth:setup`'s closing block reports what was wired instead of instructing you to paste the calls in yourself.
- The README's seeding section no longer wraps `DevDataSeeder` in `if (app()->environment('local', 'staging'))`. The seeder self-guards, and the documented form now matches exactly what the commands write.

### Development
- Edits to `DatabaseSeeder` are AST-based via nikic/php-parser's format-preserving printer, so they coexist with unrelated changes: detection is position-independent and recognises imported, fully-qualified, array-form, and nested calls; insertion adds only what is missing; removal takes only the package's entries and is pinned by a byte-for-byte wire-then-unwire round-trip test.
- `applyTransient()` is extracted from `UserModelModifier` into a shared `TransientFileWriter`, so every editor that rewrites a consumer's source file uses one restore-on-failure implementation.
- New drift guard: every seeder class the wiring writes into consumers' files must autoload and extend `Illuminate\Database\Seeder`.

## [1.2.2] - 2026-08-11

### Changed
- **Breaking:** the dev-user password env var is now `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD` (was `JAMESGIFFORD_AUTH_DEV_PASSWORD`), and its config key is now `users_password` (was `password`) in `config/jamesgifford/auth-dev.php`. Both names now say what the password is for, rather than sitting ambiguously beside the `environments`, `accounts`, and `users` keys. There is no fallback to the old name — rename it in your `.env` and, if you published the dev config, in that file. The default value is unchanged (`password`).

### Added
- `jamesgifford:auth:setup --with-dev-data` now names `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD` in its pre-lock pause, as a copy/paste `.env` line alongside the existing ID-offset variables. Env values are resolved when the app boots, so the pause teaches the Ctrl-C-edit-re-run path (matching the ID-offset guidance) rather than implying a mid-pause `.env` edit can reach the current run. The reminder prints only when the cast will actually be seeded — the seeder's environment allowlist is consulted, not just the flag. Runs without `--with-dev-data` are unchanged.
- `jamesgifford:auth:seed-dev-data` warns after seeding when the cast was seeded with the default password (`password`), naming the env var to set. Because the warning lives in the seed command itself, it also reaches `setup --force --with-dev-data` and other non-interactive runs, which never see the educational pause.
- The seed command likewise warns when the dev config still carries the legacy `password` key (the pre-1.2.2 name): the key is ignored, and the warning says to rename it to `users_password`. The seeder logs the same warning for `DatabaseSeeder`-driven runs (`migrate:fresh --seed`).
- The seeder resolves `users_password` from the config files on disk (published file first, then the package default) whenever the key is missing from the live config — a config cache built before this release would otherwise skip `mergeConfigFrom` and silently seed the default password even with the env var set. Mirrors the staleness remedy install already applies to role seeding.

### Development
- New drift guard: every `JAMESGIFFORD_AUTH_*` name appearing in `src/`, `config/`, `database/`, `routes/`, `README.md`, or the Boost skill must be one a config file actually reads via `env()`. This catches a class of failure behavior tests cannot — a command that *prints* a variable name, and a test asserting that same literal, can agree with each other while both disagree with config, leaving a green suite that tells developers to set a variable nothing reads. `CHANGELOG.md` is excluded, since it records names as they were at past releases.
- `composer check` now runs the fast SQLite suite (Pint + PHPStan + `test:sqlite`) so the day-to-day gate takes seconds rather than minutes; the new `composer check:full` runs the same gate against MariaDB. CI is unchanged — it executes the full MariaDB suite on every push, so driver-real behavior is still gated before merge.
- The `test` and `test:sqlite` scripts disable Composer's process timeout via `Composer\Config::disableProcessTimeout`. Composer's 300-second default aborted the MariaDB suite (~14 minutes) partway through with a timeout error rather than a test failure, which made `composer test` and `composer check:full` unusable locally. The default timeout still applies to every other script, so a wedged formatter or analyser fails visibly instead of hanging.
- Dist hygiene: a new `.gitattributes` marks `tests/`, `.github/`, and tooling config (`phpunit.xml`, `phpstan.neon`, `pint.json`) as `export-ignore`, so `composer require` archives ship only what a consuming application needs.
- Guard hardening: the transfer-immutability guard scans `src/Transfers/` recursively and fails loudly on a file whose class does not autoload from its path (previously both cases were silently skipped); the published dev-config guard rejects a per-user `'password'` literal in addition to the top-level `'users_password'` key.

## [1.2.1] - 2026-08-05

Development infrastructure only — **no runtime code changed**. Nothing under
`src/` or `database/` differs from 1.2.0, so upgrading changes nothing for a
consuming application.

### Changed
- The test suite now runs against **MariaDB by default** (the package's actual deployment target) instead of SQLite; `composer test:sqlite` remains as the fast in-memory development loop. The base test case purges the schema before each test on real drivers, restoring the hermetic per-test semantics sqlite `:memory:` provided implicitly.
- CI's test matrix runs against a MariaDB service container (PHP 8.4/8.5 × Laravel 13).

### Added
- Real-driver offset verification: tests now prove `apply-id-offsets` (and install's offset step) genuinely move `AUTO_INCREMENT` — the next inserted row lands exactly at the configured offset. Previously this was a manual-verification item because SQLite cannot execute the statement. Driver-specific assertions guard themselves by the active driver.

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

[Unreleased]: https://github.com/jamesgifford/auth/compare/v1.2.1...HEAD
[1.2.1]: https://github.com/jamesgifford/auth/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/jamesgifford/auth/compare/v1.1.4...v1.2.0
[1.1.4]: https://github.com/jamesgifford/auth/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/jamesgifford/auth/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/jamesgifford/auth/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/jamesgifford/auth/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/jamesgifford/auth/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/jamesgifford/auth/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/jamesgifford/auth/releases/tag/v0.1.0
