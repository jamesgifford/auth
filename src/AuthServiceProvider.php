<?php

declare(strict_types=1);

namespace Progravity\Auth;

use Illuminate\Support\ServiceProvider;
use Progravity\Auth\Accounts\Services\AccountService;
use Progravity\Auth\Console\Commands\PublicIdCheckCommand;
use Progravity\Auth\Console\Commands\PublicIdResetCommand;
use Progravity\Auth\Console\Commands\PublicIdSetupCommand;
use Progravity\Auth\Console\Commands\PublicIdStatusCommand;
use Progravity\Auth\PublicId\AlphabetRegistry;
use Progravity\Auth\PublicId\Config\ConfigFingerprint;
use Progravity\Auth\PublicId\Config\ConfigGuard;
use Progravity\Auth\PublicId\Config\LockFile;
use Progravity\Auth\PublicId\Config\PublicIdConfig;
use Progravity\Auth\PublicId\Generator;
use Progravity\Auth\PublicId\PrefixRegistry;
use Progravity\Auth\PublicId\Validator;
use Progravity\Auth\Roles\RolesConfig;

/**
 * Progravity Auth package service provider.
 *
 * Responsibilities:
 *  - Merge package config defaults under the `progravity.auth` namespace
 *  - Bind public_id services (config wrapper, generator, validator, registries, lock file, guard) as singletons
 *  - Publish the config file to the consumer's config/progravity directory
 *  - On boot: assert the locked fingerprint matches current config, then register
 *    config-mapped models with the prefix registry and check for collisions
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/auth.php', 'progravity.auth');

        $this->app->singleton(AlphabetRegistry::class, function () {
            return new AlphabetRegistry;
        });

        $this->app->singleton(PublicIdConfig::class, function ($app) {
            return new PublicIdConfig(
                config('progravity.auth.public_id'),
                $app->make(AlphabetRegistry::class),
            );
        });

        $this->app->singleton(Generator::class, function ($app) {
            return new Generator($app->make(PublicIdConfig::class));
        });

        $this->app->singleton(Validator::class, function ($app) {
            return new Validator($app->make(PublicIdConfig::class));
        });

        $this->app->singleton(PrefixRegistry::class, function ($app) {
            return new PrefixRegistry($app->make(PublicIdConfig::class));
        });

        $this->app->singleton(LockFile::class, function ($app) {
            $config = $app->make(PublicIdConfig::class);
            $path = $config->lockFilePath() ?? config_path('progravity/auth.lock.json');

            return new LockFile($path);
        });

        $this->app->singleton(ConfigFingerprint::class, function () {
            return new ConfigFingerprint;
        });

        $this->app->singleton(ConfigGuard::class, function ($app) {
            return new ConfigGuard(
                $app->make(PublicIdConfig::class),
                $app->make(LockFile::class),
                $app->make(ConfigFingerprint::class),
            );
        });

        $this->app->singleton(RolesConfig::class, function () {
            return new RolesConfig(
                config('progravity.auth.roles', []),
            );
        });

        $this->app->singleton(AccountService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/auth.php' => config_path('progravity/auth.php'),
        ], 'progravity-auth-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'progravity-auth-migrations');

        $this->app->make(ConfigGuard::class)->assertMatches();

        $registry = $this->app->make(PrefixRegistry::class);
        $config = $this->app->make(PublicIdConfig::class);

        foreach (array_keys($config->prefixes()) as $modelClass) {
            if (class_exists($modelClass)) {
                $registry->register($modelClass);
            }
        }

        $registry->assertNoCollisions();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PublicIdSetupCommand::class,
                PublicIdStatusCommand::class,
                PublicIdResetCommand::class,
                PublicIdCheckCommand::class,
            ]);
        }
    }
}
