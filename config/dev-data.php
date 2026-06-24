<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JamesGifford Auth — dev data
|--------------------------------------------------------------------------
|
| A ready-to-use, deterministic LOCAL dev cast, seeded by
| `php artisan jamesgifford:auth:seed-dev-data`. This file ships
| PRE-POPULATED — a fresh install is immediately seedable. Edit or extend the
| cast freely; it's just structure.
|
| These users take low ids so real records can start above a configured offset
| (see auth.php → id_offsets).
|
| Publish it deliberately to customize (it is dev-only and NOT published during
| a normal install):
|
|     php artisan vendor:publish --tag=jamesgifford-auth-dev-data
|
| Intended local ordering (the package does NOT orchestrate this — wire it into
| your own DatabaseSeeder or a local `setup` command):
|
|     migrate:fresh
|       → jamesgifford:auth:install (or seed roles)
|       → jamesgifford:auth:seed-dev-data
|       → jamesgifford:auth:apply-id-offsets
|
| seed-dev-data deliberately does NOT call apply-id-offsets — keep them
| independent so you control the order.
|
*/

return [

    // Environments in which seed-dev-data may run. The command FAILS CLOSED:
    // if the current environment is not in this list, it refuses. 'production'
    // is ALWAYS refused, independently of this list.
    'environments' => ['local', 'staging'],

    // Shared password for every seeded dev user. Sourced from the environment
    // (JAMESGIFFORD_AUTH_DEV_PASSWORD in your .env) so no credential is committed
    // here; it is hashed at seed time and never stored in plaintext.
    'password' => env('JAMESGIFFORD_AUTH_DEV_PASSWORD', 'password'),

    // The default dev cast — ready to seed. Each entry is idempotent
    // (updateOrCreate on email). Optional 'account' creates an account owned by
    // that user via the package's AccountService (so the single-owner invariant
    // holds). Optional 'members' attach OTHER dev users to that account with a
    // role. Together this cast exercises roles, multi-account membership,
    // account switching, and the floating (no-account) state.
    'users' => [

        // Owner of "Acme Inc"; the account also hosts the admin, member, and
        // multi-account users below.
        [
            'name' => 'Owner',
            'email' => 'owner@dev.test',
            'account' => 'Acme Inc',
            'members' => [
                ['email' => 'admin@dev.test', 'role' => 'admin'],
                ['email' => 'member@dev.test', 'role' => 'member'],
                ['email' => 'multi@dev.test', 'role' => 'member'],
            ],
        ],

        // Admin of "Acme Inc".
        ['name' => 'Admin', 'email' => 'admin@dev.test'],

        // Member of "Acme Inc".
        ['name' => 'Member', 'email' => 'member@dev.test'],

        // Belongs to TWO accounts: owns "Beta LLC" and is a member of "Acme Inc"
        // (above) — exercises multiple accounts / switching.
        [
            'name' => 'Multi-Account User',
            'email' => 'multi@dev.test',
            'account' => 'Beta LLC',
        ],

        // Owns no account and belongs to none — exercises the floating state.
        ['name' => 'Floating User', 'email' => 'floating@dev.test'],

    ],

];
