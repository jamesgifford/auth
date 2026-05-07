# Progravity Auth

Reusable authentication scaffolding for Laravel applications. Built and maintained by Progravity LLC for use across multiple internal apps; released publicly under MIT in case it's useful to anyone else.

The package will eventually cover public IDs, accounts, account membership, invitations, and registration flows. Currently only the public ID subsystem is implemented.

## Status

Version 0.1.0 — Public ID subsystem complete. Account and invitation features not yet implemented.

## Requirements

- PHP 8.3 or higher
- Laravel 12 or 13

## Installation

This package is distributed via its Git repository. Add the repository to your application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/progravity/auth.git"
        }
    ]
}
```

Then require the package:

```bash
composer require progravity/auth:^0.1.0
```

## Quick Start

### 1. Publish the configuration

```bash
php artisan vendor:publish --tag=progravity-auth-config
```

This creates `config/progravity/auth.php`. Review the public_id settings — particularly the alphabet, body length, and checksum options. These values are locked at setup and cannot be changed afterward without invalidating all previously generated IDs.

### 2. Run setup

```bash
php artisan progravity:public-id:setup
```

The interactive wizard displays the configuration to be locked, generates sample IDs, shows collision math, and writes a lock file at `config/progravity/auth.lock.json` once you confirm.

**Important:** Commit `config/progravity/auth.lock.json` to your repository. The package verifies on every boot that the current config matches the lock file; environments without a lock file skip integrity verification until setup is run.

### 3. Apply the trait to your models

```php
use Illuminate\Database\Eloquent\Model;
use Progravity\Auth\PublicId\Concerns\HasPublicId;

class Account extends Model
{
    use HasPublicId;

    public function publicIdPrefix(): string
    {
        return 'acc';
    }
}
```

Alternatively, register prefixes in `config/progravity/auth.php` under `public_id.prefixes` instead of overriding the method on each model:

```php
'prefixes' => [
    App\Models\Account::class => 'acc',
    App\Models\Workspace::class => 'wsp',
],
```

### 4. Add the column to your migrations

Use `PublicId::maxLength()` to size the column correctly based on your locked configuration:

```php
use Progravity\Auth\PublicId\PublicId;

Schema::create('accounts', function (Blueprint $table) {
    $table->id();
    $table->string('public_id', PublicId::maxLength())->unique();
    // ...
});
```

The trait auto-generates the `public_id` value when models are created. Route-model binding automatically resolves by `public_id` instead of `id`.

## Validation

Use the `ValidPublicId` rule in FormRequests or inline validation:

```php
use Progravity\Auth\PublicId\Rules\ValidPublicId;

$request->validate([
    'account_id' => ['required', new ValidPublicId('acc')],
    'reference' => ['required', new ValidPublicId()], // any prefix
]);
```

## Console Commands

| Command | Purpose |
|---------|---------|
| `progravity:public-id:setup` | Interactive wizard to lock the public_id configuration. Run once after install. |
| `progravity:public-id:status` | Display current configuration, lock state, and registered prefixes. Read-only. |
| `progravity:public-id:check` | Verify prefix registry integrity. Suitable for CI. |
| `progravity:public-id:reset` | Clear the lock file. Destructive; requires explicit flags. |

## Public ID Format

By default, public IDs look like `acc_a1b2c3d4e5f6g7h8i9jkes`:

- Prefix: 1–7 lowercase letters identifying the model type
- Separator: underscore
- Body: 18 random characters from `a-z0-9`
- Checksum: 2-character typo-detection code

All of these are configurable at setup. Once locked, the format cannot be changed without invalidating existing IDs.

## Lock File Policy

The lock file at `config/progravity/auth.lock.json` is part of your application's identity. It must be committed to version control and must travel with your codebase across environments. The boot guard verifies on every application start that the current config matches the lock file; mismatches throw a `PublicIdConfigLockedException`.

If you genuinely need to change the locked format (rare; only valid before any IDs have been issued in production), use `progravity:public-id:reset` followed by `progravity:public-id:setup`.

## Documentation

API documentation lives in the source code as docblocks. Key entry points:

- `Progravity\Auth\PublicId\PublicId` — static facade for generation, validation, and length helpers
- `Progravity\Auth\PublicId\Concerns\HasPublicId` — Eloquent trait
- `Progravity\Auth\PublicId\Rules\ValidPublicId` — validation rule

## License

MIT. See [LICENSE](LICENSE).

## Author

James Gifford / Progravity LLC.
