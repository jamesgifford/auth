<?php

declare(strict_types=1);

use App\Models\User;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\PublicId\Checksum\PositionalSumChecksum;

return [

    /*
    |--------------------------------------------------------------------------
    | Public ID Configuration
    |--------------------------------------------------------------------------
    |
    | These values control how public IDs are generated and validated. Once
    | you run `php artisan jamesgifford:public-id:setup`, the format-defining
    | values below are locked. Changing them after lock requires explicit
    | reset and will invalidate all previously generated IDs.
    |
    | Only `prefix_max_length` and `prefixes` can be changed safely after
    | lock. All other values under `public_id` are part of the locked
    | fingerprint.
    |
    */

    'public_id' => [

        // Maximum allowed length for a model's prefix string. Not part of
        // the locked fingerprint — increasing this post-lock is safe.
        'prefix_max_length' => 7,

        // Separator character between prefix and body. Locked at setup.
        'separator' => '_',

        'body' => [
            // Length of the random body portion of every public ID.
            // Locked at setup.
            'length' => 18,

            // Alphabet used for the body. Either a preset name registered
            // in AlphabetRegistry (e.g. 'lowercase_alphanumeric',
            // 'crockford', 'nolookalikes') or a raw alphabet string.
            // Locked at setup.
            'alphabet' => 'lowercase_alphanumeric',
        ],

        'checksum' => [
            // Whether to append a checksum to every public ID. Disabling
            // produces shorter IDs but loses typo detection. Locked at setup.
            'enabled' => true,

            // Length of the checksum suffix when enabled. Locked at setup.
            'length' => 2,

            // Fully qualified class name of the checksum strategy.
            // Must implement
            // JamesGifford\Auth\PublicId\Checksum\ChecksumStrategy.
            // Locked at setup.
            'strategy' => PositionalSumChecksum::class,
        ],

        // Path to the lock file. Null uses the default location at
        // config_path('jamesgifford/auth.lock.json').
        'lock_file_path' => null,

        // Map of model class FQCNs to their public_id prefix. Models that
        // implement publicIdPrefix() override this map. Not part of the
        // locked fingerprint.
        //
        // Example:
        //   App\Models\Workspace::class => 'wsp',
        //   App\Models\Project::class => 'prj',
        'prefixes' => [
            //
        ],

        // Custom alphabet presets to register beyond the built-ins. Keys
        // are preset names, values are raw alphabet strings. Useful for
        // sharing a non-standard alphabet across multiple apps without
        // copy-pasting the raw string.
        //
        // Example:
        //   'my_custom_alphabet' => 'abcdefghjkmnpqrstuvwxyz23456789',
        'custom_alphabet_presets' => [
            //
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Resolution
    |--------------------------------------------------------------------------
    |
    | The fully-qualified class names of models the package uses. Override
    | any of these to use your own subclass.
    |
    */

    'models' => [
        'user' => User::class,
        'account' => Account::class,
        'account_role' => AccountRole::class,
        'account_user' => AccountUser::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Roles
    |--------------------------------------------------------------------------
    |
    | The roles available within an account. The keys are stable identifiers
    | used in code; the names and descriptions are displayed in UI.
    |
    | System roles (system => true) ship with the package and are seeded
    | automatically. The 'owner' role is required and cannot be removed,
    | though its name and description may be customized.
    |
    | To add custom roles, add entries below with system => false.
    |
    */

    'roles' => [
        'owner' => [
            'name' => 'Owner',
            'description' => 'Full control over the account, including deletion and ownership transfer.',
            'system' => true,
            'sort_order' => 1,
        ],
        'admin' => [
            'name' => 'Administrator',
            'description' => 'Manage account settings, members, and resources.',
            'system' => true,
            'sort_order' => 2,
        ],
        'member' => [
            'name' => 'Member',
            'description' => 'Standard access to account resources.',
            'system' => true,
            'sort_order' => 3,
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Read-only access to account resources.',
            'system' => true,
            'sort_order' => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    |
    | Behavior options for the accounts subsystem.
    |
    */

    'accounts' => [
        // Default account name when one isn't provided (e.g., during
        // registration). The string {name} is replaced with the user's name.
        'default_name_template' => "{name}'s Account",
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-increment ID offsets
    |--------------------------------------------------------------------------
    |
    | Optional starting values for the auto-increment counters on the users
    | and accounts tables, so real records begin above a chosen number (e.g.
    | accounts at 1001). This avoids confusing user ids with account ids and
    | reserves a low id range for deterministic dev fixtures (seeded separately).
    |
    | null = no offset (leave the table's default). An offset only sets where
    | the counter STARTS, so it takes effect on an empty (or below-offset)
    | table. Apply it with `php artisan jamesgifford:auth:apply-id-offsets`
    | AFTER migrating and after any role/dev-data seeding, so the counter lands
    | above existing records.
    |
    | Note: 'users' targets the consuming application's users table, NOT a
    | package-owned table. Supported on MySQL/MariaDB and PostgreSQL; a no-op
    | on SQLite (which can't honor this cleanly).
    |
    */

    'id_offsets' => [
        'users' => null,      // e.g. 11
        'accounts' => null,   // e.g. 1001
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP plumbing
    |--------------------------------------------------------------------------
    |
    | The package's frontend-agnostic HTTP layer: the account switch/list
    | routes and the EnsureCurrentAccount middleware. The controllers only
    | redirect or return JSON — never a view — so this works identically on
    | Livewire, Inertia, Blade, or API stacks. The service provider checks
    | `enabled` before registering the routes and the middleware alias.
    |
    */

    'http' => [

        // Master switch for the HTTP plumbing. Set to false (or run
        // `php artisan jamesgifford:auth:install --without-http`) to register
        // no routes and no middleware alias — useful if you wire your own.
        'enabled' => true,

        'middleware' => [
            // EnsureCurrentAccount: route name to send a "floating" user
            // (authenticated, but with no current account) to. null means
            // auto-assign their first account and continue instead of redirecting.
            'redirect_floating_to' => null,

            // EnsureCurrentAccount: route name to send a user to when their
            // current account has gone (deleted, or membership lost). null means
            // fall back to the floating behavior (auto-assign another account,
            // else continue).
            'redirect_missing_to' => null,
        ],
    ],

];
