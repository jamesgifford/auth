<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\PublicId;

use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModel;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModelWithoutOverride;
use JamesGifford\Auth\Tests\TestCase;

/**
 * The package's default public ID prefixes are `user` (users) and `account`
 * (accounts). Resolution order for a model: its own publicIdPrefix() override
 * wins; otherwise the config `prefixes` map; otherwise it is unregistered.
 */
class PrefixDefaultsTest extends TestCase
{
    public function test_account_default_prefix_is_account(): void
    {
        // Account declares publicIdPrefix() => 'account' (the authoritative
        // default for the package-owned model).
        $prefix = $this->app->make(PrefixRegistry::class)->prefixFor(Account::class);

        $this->assertSame('account', $prefix);

        $id = PublicId::generate($prefix);
        $this->assertStringStartsWith('account_', $id);
        $this->assertTrue(PublicId::isValid($id));
    }

    public function test_user_default_prefix_is_user_via_config(): void
    {
        // The user model is consumer-owned, so its default lives in the config
        // `prefixes` map (the shipped config maps the user model to 'user').
        // A model with no publicIdPrefix() override resolves through that map.
        $registry = $this->registryWithPrefixes([FixtureModelWithoutOverride::class => 'user']);

        $prefix = $registry->prefixFor(FixtureModelWithoutOverride::class);

        $this->assertSame('user', $prefix);

        $id = PublicId::generate($prefix);
        $this->assertStringStartsWith('user_', $id);
        $this->assertTrue(PublicId::isValid($id));
    }

    public function test_shipped_config_declares_user_and_account_defaults(): void
    {
        $config = require dirname(__DIR__, 3).'/config/auth.php';
        $prefixes = $config['public_id']['prefixes'];

        // Keyed by the user model (App\Models\User) and the package Account model.
        $this->assertSame('user', $prefixes['App\\Models\\User'] ?? null);
        $this->assertSame('account', $prefixes[Account::class] ?? null);
    }

    public function test_both_default_prefixes_validate_against_max_length_and_format(): void
    {
        $max = $this->app->make(PublicIdConfig::class)->prefixMaxLength();

        foreach (['user', 'account'] as $prefix) {
            $this->assertLessThanOrEqual($max, strlen($prefix), "'{$prefix}' exceeds prefix_max_length {$max}");
            $this->assertSame(1, preg_match('/^[a-z]+$/', $prefix), "'{$prefix}' is not all lowercase letters");

            $id = PublicId::generate($prefix);
            $this->assertTrue(PublicId::isValid($id), "generated id for '{$prefix}' must be valid");
            $this->assertSame($prefix, PublicId::prefixOf($id));
        }
    }

    public function test_model_method_overrides_config_prefix(): void
    {
        // FixtureModel declares publicIdPrefix() => 'fix'. Even with a conflicting
        // config entry, the model's own method wins (documented resolution order).
        $registry = $this->registryWithPrefixes([FixtureModel::class => 'zzz']);

        $this->assertSame('fix', $registry->prefixFor(FixtureModel::class));
    }

    /**
     * A fresh registry resolving against an exact config `prefixes` map.
     *
     * @param  array<string, string>  $prefixes
     */
    private function registryWithPrefixes(array $prefixes): PrefixRegistry
    {
        config(['jamesgifford.auth.public_id.prefixes' => $prefixes]);
        $this->app->forgetInstance(PublicIdConfig::class);
        $this->app->forgetInstance(PrefixRegistry::class);

        return $this->app->make(PrefixRegistry::class);
    }
}
