<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JamesGifford Auth — development data
|--------------------------------------------------------------------------
|
| A ready-to-use, deterministic development cast: users, the accounts they own,
| and the memberships between them. Seeded by the DevDataSeeder (see below) —
| either through `php artisan jamesgifford:auth:seed-dev-data`, or by calling
| the seeder from your app's DatabaseSeeder.
|
| This file ships PRE-POPULATED, so a fresh install is immediately seedable.
| Everything here is just structure — edit it freely to match your app.
|
| WHERE THIS SEEDS
| ----------------
| Development only. The seeder runs ONLY in the environments listed below
| (default: local, staging) and NEVER in production — the guard lives in the
| seeder itself, so `migrate:fresh --seed` in production silently skips this
| cast instead of failing.
|
| Recommended DatabaseSeeder wiring (database/seeders/DatabaseSeeder.php):
|
|     public function run(): void
|     {
|         // Required account roles — ALL environments (reads config/jamesgifford/auth.php).
|         $this->call(\JamesGifford\Auth\Database\Seeders\AccountRoleSeeder::class);
|
|         // Development fixtures — local & staging only (reads this file).
|         if (app()->environment('local', 'staging')) {
|             $this->call(\JamesGifford\Auth\Database\DevDataSeeder::class);
|         }
|     }
|
| Publish this file to customize it (dev-only; NOT published on a normal install):
|
|     php artisan vendor:publish --tag=jamesgifford-auth-dev
|
*/

return [

    // Environments in which the dev cast may seed. The seeder FAILS CLOSED: in
    // any environment not listed here it silently skips. 'production' is ALWAYS
    // refused, independently of this list.
    //
    // To allow another environment, add it here (e.g. 'demo').
    'environments' => ['local', 'staging'],

    // Shared password for EVERY seeded dev user. Sourced from the environment
    // (JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD in your .env) so no credential is
    // committed here; it is hashed at seed time and never stored in plaintext.
    //
    // The password is NOT set per-user in this file — change it in .env.
    'users_password' => env('JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD', 'password'),

    // ---- Accounts -----------------------------------------------------------
    //
    // Each account is declared ONCE here, with its name and its owner (by email).
    // The owner becomes the account's Owner (satisfying the single-owner
    // invariant) and gets the owner membership automatically — you do NOT also
    // list the owner under the account in `users` memberships below.
    //
    // TO ADD AN ACCOUNT: add an entry with a unique 'name' and an 'owner' email
    // that also appears in the `users` list, e.g.
    //     ['name' => 'Gamma Co', 'owner' => 'owner@dev.test'],
    'accounts' => [
        ['name' => 'Acme Inc', 'owner' => 'owner@dev.test'],
        ['name' => 'Beta LLC', 'owner' => 'multi@dev.test'],
    ],

    // ---- Users --------------------------------------------------------------
    //
    // Each user is declared ONCE here with a name and email. From the user's own
    // perspective you can optionally declare:
    //
    //   'memberships'     accounts this user belongs to (BEYOND any they own),
    //                     each with a role: [['account' => 'Acme Inc', 'role' => 'admin']]
    //   'current_account' the account name to set as this user's active account
    //                     (the user must own or be a member of it). Omit it to
    //                     leave the user with no active account.
    //
    // TO ADD A USER:            add an entry with 'name' and 'email'.
    // TO CHANGE AN EMAIL:       edit the 'email' on the user's entry (and any
    //                           'owner'/references to it above).
    // TO PUT A USER IN MORE
    //   ACCOUNTS WITH ROLES:    add entries to that user's 'memberships'.
    // TO SET A CURRENT ACCOUNT: add 'current_account' => 'Account Name'.
    'users' => [

        // Owner of "Acme Inc" (declared in `accounts`). The owner membership is
        // implied by ownership — no 'memberships' entry needed here.
        ['name' => 'Owner', 'email' => 'owner@dev.test'],

        // Admin of "Acme Inc".
        ['name' => 'Admin', 'email' => 'admin@dev.test', 'memberships' => [
            ['account' => 'Acme Inc', 'role' => 'admin'],
        ]],

        // Member of "Acme Inc".
        ['name' => 'Member', 'email' => 'member@dev.test', 'memberships' => [
            ['account' => 'Acme Inc', 'role' => 'member'],
        ]],

        // Belongs to TWO accounts: owns "Beta LLC" (via `accounts`) and is a
        // member of "Acme Inc". Its active account is set to "Beta LLC".
        ['name' => 'Multi-Account User', 'email' => 'multi@dev.test', 'memberships' => [
            ['account' => 'Acme Inc', 'role' => 'member'],
        ], 'current_account' => 'Beta LLC'],

        // Owns nothing and belongs to nothing — the floating state.
        ['name' => 'Floating User', 'email' => 'floating@dev.test'],

    ],

];
