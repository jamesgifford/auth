<?php

declare(strict_types=1);

namespace JamesGifford\Auth;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use JamesGifford\Auth\Accounts\Services\AccountIntegrityService;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Console\Commands\AuthApplyIdOffsetsCommand;
use JamesGifford\Auth\Console\Commands\AuthInstallCommand;
use JamesGifford\Auth\Console\Commands\AuthPublishModelsCommand;
use JamesGifford\Auth\Console\Commands\AuthSeedDevDataCommand;
use JamesGifford\Auth\Console\Commands\AuthSetupCommand;
use JamesGifford\Auth\Console\Commands\AuthUninstallCommand;
use JamesGifford\Auth\Console\Commands\PublicIdCheckCommand;
use JamesGifford\Auth\Console\Commands\PublicIdResetCommand;
use JamesGifford\Auth\Console\Commands\PublicIdSetupCommand;
use JamesGifford\Auth\Console\Commands\PublicIdStatusCommand;
use JamesGifford\Auth\Http\Middleware\EnsureCurrentAccount;
use JamesGifford\Auth\Installer\UserModelModifier;
use JamesGifford\Auth\Listeners\CreateAccountOnRegistration;
use JamesGifford\Auth\PublicId\AlphabetRegistry;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\ConfigGuard;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Generator;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\PublicId\Validator;
use JamesGifford\Auth\Roles\RolesConfig;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PhpParserPrinter;

/**
 * JamesGifford Auth package service provider.
 *
 * Responsibilities:
 *  - Merge package config defaults under the `jamesgifford.auth` namespace
 *  - Bind public_id services (config wrapper, generator, validator, registries, lock file, guard) as singletons
 *  - Publish the config file to the consumer's config/jamesgifford directory
 *  - On boot: assert the locked fingerprint matches current config, then register
 *    config-mapped models with the prefix registry and check for collisions
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/auth.php', 'jamesgifford.auth');

        // Dev-data defaults live under a sibling key so the published file
        // (config/jamesgifford/auth-dev.php) auto-loads to the same key.
        $this->mergeConfigFrom(__DIR__.'/../config/auth-dev.php', 'jamesgifford.auth-dev');

        $this->app->singleton(AlphabetRegistry::class, function () {
            return new AlphabetRegistry;
        });

        $this->app->singleton(PublicIdConfig::class, function ($app) {
            return new PublicIdConfig(
                config('jamesgifford.auth.public_id'),
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
            $path = $config->lockFilePath() ?? config_path('jamesgifford/auth.lock.json');

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
                config('jamesgifford.auth.roles', []),
            );
        });

        $this->app->singleton(AccountService::class);

        $this->app->singleton(AccountIntegrityService::class);

        $this->app->singleton(Parser::class, function () {
            return (new ParserFactory)->createForNewestSupportedVersion();
        });

        $this->app->singleton(PhpParserPrinter::class);

        $this->app->singleton(UserModelModifier::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/auth.php' => config_path('jamesgifford/auth.php'),
        ], 'jamesgifford-auth-config');

        // Migrations are PUBLISHED into the consumer's database/migrations as
        // real files (never loadMigrationsFrom'd), so they run in the normal
        // merged-timestamp order alongside the app's own migrations. This
        // registration exposes them to `vendor:publish` for completeness, but
        // the blessed path is `jamesgifford:auth:install`, which publishes them
        // with FRESH timestamps generated at setup time (see PackageMigrations)
        // so they sort correctly — after the app's system migrations, before its
        // project migrations. A raw `vendor:publish` would copy the frozen source
        // timestamps verbatim and is not the recommended install path.
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'jamesgifford-auth-migrations');

        // Dev-data config is published only on deliberate request (its own tag),
        // never during a normal install — it is dev-only.
        $this->publishes([
            __DIR__.'/../config/auth-dev.php' => config_path('jamesgifford/auth-dev.php'),
        ], 'jamesgifford-auth-dev');

        $this->app->make(ConfigGuard::class)->assertMatches();

        $registry = $this->app->make(PrefixRegistry::class);
        $config = $this->app->make(PublicIdConfig::class);

        foreach (array_keys($config->prefixes()) as $modelClass) {
            if (class_exists($modelClass)) {
                $registry->register($modelClass);
            }
        }

        $registry->assertNoCollisions();

        // Auto-account-creation on registration. Single, isolated wiring point:
        // a future config gate would wrap just this one line. Activates by the
        // package being installed; no install-command change, no config flag.
        Event::listen(Registered::class, CreateAccountOnRegistration::class);

        // Frontend-agnostic HTTP plumbing (account switch/list routes +
        // EnsureCurrentAccount middleware). Gated by a single config flag so
        // `--without-http` (which sets it false) skips all of it.
        if (config('jamesgifford.auth.http.enabled', true)) {
            // Explicit {account} binder for the CONFIGURED account class, so a
            // models.account override is honored by route-model binding (the
            // controllers' base-class type-hints no longer drive resolution).
            // Registered here, not in routes/web.php — route files are skipped
            // entirely under route:cache, which would silently drop a binder
            // declared there. Note Router::model() binds by parameter name
            // globally: consumer routes using {account} get this binder too
            // (it resolves by public_id via the model's getRouteKeyName()).
            $this->app['router']->model('account', PackageModels::account());

            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            $this->app['router']->aliasMiddleware('auth.current-account', EnsureCurrentAccount::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                PublicIdSetupCommand::class,
                PublicIdStatusCommand::class,
                PublicIdResetCommand::class,
                PublicIdCheckCommand::class,
                AuthInstallCommand::class,
                AuthUninstallCommand::class,
                AuthSetupCommand::class,
                AuthPublishModelsCommand::class,
                AuthApplyIdOffsetsCommand::class,
                AuthSeedDevDataCommand::class,
            ]);
        }
    }
}
