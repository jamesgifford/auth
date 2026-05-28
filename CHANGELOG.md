# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
