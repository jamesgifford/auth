<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModel;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModelCollisionA;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModelCollisionB;
use JamesGifford\Auth\Tests\TestCase;

class PublicIdCheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::clearBootedModels();
    }

    public function test_all_checks_pass_with_no_registered_prefixes(): void
    {
        $this->artisan('jamesgifford:public-id:check')
            ->expectsOutputToContain('Class autoload check passed')
            ->expectsOutputToContain('Prefix collision check passed')
            ->expectsOutputToContain('Prefix format check passed')
            ->expectsOutputToContain('All checks passed')
            ->assertSuccessful();
    }

    public function test_all_checks_pass_with_valid_registered_prefix(): void
    {
        $this->app->make(PrefixRegistry::class)->register(FixtureModel::class);

        $this->artisan('jamesgifford:public-id:check')
            ->expectsOutputToContain('Class autoload check passed')
            ->expectsOutputToContain('Prefix collision check passed')
            ->expectsOutputToContain('Prefix format check passed')
            ->expectsOutputToContain(FixtureModel::class)
            ->expectsOutputToContain('All checks passed')
            ->assertSuccessful();
    }

    public function test_class_autoload_check_fails_for_nonexistent_class(): void
    {
        // Patch config and force re-resolve of PublicIdConfig so the new
        // prefixes array surfaces in the command.
        config(['jamesgifford.auth.public_id.prefixes' => [
            'App\\Models\\NonexistentTypo' => 'typ',
        ]]);
        $this->app->forgetInstance(PublicIdConfig::class);
        $this->app->forgetInstance(PrefixRegistry::class);

        $this->artisan('jamesgifford:public-id:check')
            ->expectsOutputToContain('Class autoload check failed')
            ->expectsOutputToContain('App\\Models\\NonexistentTypo')
            ->expectsOutputToContain('typ')
            ->assertFailed();
    }

    public function test_collision_check_fails_when_two_models_share_a_prefix(): void
    {
        $registry = $this->app->make(PrefixRegistry::class);
        $registry->register(FixtureModelCollisionA::class);
        $registry->register(FixtureModelCollisionB::class);

        $this->artisan('jamesgifford:public-id:check')
            ->expectsOutputToContain('Prefix collision check failed')
            ->expectsOutputToContain("'col'")
            ->expectsOutputToContain(FixtureModelCollisionA::class)
            ->expectsOutputToContain(FixtureModelCollisionB::class)
            ->assertFailed();
    }

    public function test_failure_exits_with_nonzero_status(): void
    {
        config(['jamesgifford.auth.public_id.prefixes' => [
            'App\\Models\\NonexistentTypo' => 'typ',
        ]]);
        $this->app->forgetInstance(PublicIdConfig::class);
        $this->app->forgetInstance(PrefixRegistry::class);

        $exitCode = $this->artisan('jamesgifford:public-id:check')->run();

        $this->assertNotSame(0, $exitCode);
    }

    public function test_summary_line_reports_issue_count(): void
    {
        config(['jamesgifford.auth.public_id.prefixes' => [
            'App\\Models\\NonexistentOne' => 'one',
        ]]);
        $this->app->forgetInstance(PublicIdConfig::class);
        $this->app->forgetInstance(PrefixRegistry::class);

        $this->artisan('jamesgifford:public-id:check')
            ->expectsOutputToContain('1 issue found')
            ->assertFailed();
    }
}
