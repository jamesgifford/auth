<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Tests\TestCase;

/**
 * Regression for the standard `--publish-models` flow: the published
 * App\Models\Account subclass inherits publicIdPrefix() 'account' from the
 * package Account, which the provider has ALREADY registered from the config
 * prefixes map. Both classes claiming 'account' is one logical registration
 * (one inheritance chain), so `jamesgifford:public-id:check` must pass — a
 * reported collision here would flag every standard install as broken.
 */
final class PublishedModelsPrefixCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::clearBootedModels();
        $this->cleanPublishedModels();
    }

    protected function tearDown(): void
    {
        $this->cleanPublishedModels();
        parent::tearDown();
    }

    public function test_public_id_check_passes_after_publish_models_flow(): void
    {
        Artisan::call('jamesgifford:auth:publish-models');

        $accountFile = $this->app->path('Models'.DIRECTORY_SEPARATOR.'Account.php');
        $this->assertFileExists($accountFile);

        // App\ is not autoloadable in the package test suite, and class
        // definitions leak across the (randomized) run — require exactly once.
        if (! class_exists('App\\Models\\Account', false)) {
            require $accountFile;
        }

        config(['jamesgifford.auth.models.account' => 'App\\Models\\Account']);

        // Booting the subclass makes HasPublicId self-register it with the
        // inherited 'account' prefix, alongside the provider's registration
        // of the package base class.
        $subclass = 'App\\Models\\Account';
        new $subclass;

        $this->artisan('jamesgifford:public-id:check')
            ->expectsOutputToContain('Prefix collision check passed')
            ->assertSuccessful();
    }

    protected function defineEnvironment($app): void
    {
        // The Testbench skeleton has no App\Models\User, which would fail the
        // check command's unrelated class-autoload check. Keep the prefixes
        // map to the account chain this regression is about.
        $app['config']->set('jamesgifford.auth.public_id.prefixes', [
            Account::class => 'account',
        ]);
    }

    private function cleanPublishedModels(): void
    {
        if ($this->app === null) {
            return;
        }

        foreach (['Account', 'AccountUser', 'AccountRole'] as $model) {
            @unlink($this->app->path('Models').DIRECTORY_SEPARATOR.$model.'.php');
        }
    }
}
