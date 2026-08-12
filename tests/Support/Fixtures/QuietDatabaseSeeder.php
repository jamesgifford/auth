<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use JamesGifford\Auth\Database\DevDataSeeder;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

/**
 * Mirrors exactly what DatabaseSeederWiring writes into a consuming
 * application's database/seeders/DatabaseSeeder.php: WithoutModelEvents (a
 * real `migrate:fresh --seed` suppresses model events for speed) and, in
 * canonical order, the roles seeder followed by the dev-data seeder.
 *
 * Used by DevDataSeederWithoutEventsTest to reproduce, end to end, the bug
 * fixed in HasPublicId: when public_id was only ever set from a `creating`
 * event listener, WithoutModelEvents silently suppressed it and every
 * user/account insert failed on public_id's NOT NULL constraint.
 */
class QuietDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(AccountRoleSeeder::class);
        $this->call(DevDataSeeder::class);
    }
}
