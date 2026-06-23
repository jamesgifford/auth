<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JamesGifford Auth — dev data
|--------------------------------------------------------------------------
|
| Declarative, deterministic LOCAL dev fixtures seeded by
| `php artisan jamesgifford:auth:seed-dev-data`. These users take low ids so
| real records can start above a configured offset (see auth.php → id_offsets).
|
| This file is NOT published during a normal install (it is dev-only). Publish
| it deliberately to customize:
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
    'environments' => ['local', 'testing'],

    // Shared password for every seeded dev user. Sourced from the environment
    // so no credential is committed; it is hashed at seed time (never stored
    // in plaintext).
    'password' => env('JAMESGIFFORD_AUTH_DEV_PASSWORD', 'password'),

    // The dev users to seed. Each is idempotent (updateOrCreate on email).
    // Optional 'account' creates an account owned by that user via the package's
    // AccountService (so the single-owner invariant holds). Optional 'members'
    // attach OTHER dev users to that account with a role.
    //
    // Example:
    //   [
    //       'name'    => 'Dev Owner',
    //       'email'   => 'owner@example.test',
    //       'account' => "Owner's Workspace",
    //       'members' => [
    //           ['email' => 'member@example.test', 'role' => 'admin'],
    //       ],
    //   ],
    //   ['name' => 'Dev Member', 'email' => 'member@example.test'],
    'users' => [
        //
    ],

];
