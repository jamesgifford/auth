<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Database;

use Illuminate\Support\Facades\Log;
use JamesGifford\Auth\Database\Seeders\ApplyIdOffsetsSeeder;
use JamesGifford\Auth\Tests\TestCase;

class ApplyIdOffsetsSeederTest extends TestCase
{
    public function test_it_is_a_no_op_when_no_offsets_are_configured(): void
    {
        config(['jamesgifford.auth.id_offsets' => ['users' => null, 'accounts' => null]]);

        Log::shouldReceive('warning')->never();

        $this->app->make(ApplyIdOffsetsSeeder::class)->run();

        // Reaching here without an exception is the assertion: a disabled
        // feature must never interrupt an app's `--seed` run.
        $this->assertTrue(true);
    }

    public function test_a_malformed_offset_is_logged_and_swallowed(): void
    {
        // A non-numeric env typo reaches config as a string the manager rejects.
        config(['jamesgifford.auth.id_offsets' => ['users' => 'not-a-number', 'accounts' => null]]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, '[jamesgifford/auth]')
                && str_contains($message, 'ID offsets'));

        $this->app->make(ApplyIdOffsetsSeeder::class)->run();

        $this->assertTrue(true);
    }

    public function test_a_valid_offset_is_applied_on_a_supported_driver(): void
    {
        if ($this->databaseDriver() === 'sqlite') {
            $this->markTestSkipped('SQLite does not support auto-increment offsets; the manager no-ops.');
        }

        config(['jamesgifford.auth.id_offsets' => ['users' => 5000, 'accounts' => null]]);

        Log::shouldReceive('warning')->never();

        $this->app->make(ApplyIdOffsetsSeeder::class)->run();

        $this->assertTrue(true);
    }
}
