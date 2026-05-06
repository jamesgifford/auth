<?php

declare(strict_types=1);

namespace Progravity\Auth;

use Illuminate\Support\ServiceProvider;

/**
 * Progravity Auth package service provider.
 *
 * Bindings, config publishing, migration loading, and console command
 * registration will be added here as features land.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
