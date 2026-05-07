<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public ID Configuration
    |--------------------------------------------------------------------------
    |
    | These values control how public IDs are generated and validated. Once
    | you run `php artisan progravity:public-id:setup`, the format-defining
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
            // Progravity\Auth\PublicId\Checksum\ChecksumStrategy.
            // Locked at setup.
            'strategy' => \Progravity\Auth\PublicId\Checksum\PositionalSumChecksum::class,
        ],

        // Path to the lock file. Null uses the default location at
        // config_path('progravity/auth.lock.json').
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

];
