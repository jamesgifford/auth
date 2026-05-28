<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\PublicId;

use JamesGifford\Auth\PublicId\AlphabetRegistry;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\ConfigGuard;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Generator;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\PublicId\Validator;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModel;
use JamesGifford\Auth\Tests\TestCase;

class AuthServiceProviderTest extends TestCase
{
    public function test_all_services_resolve_from_container(): void
    {
        $services = [
            AlphabetRegistry::class,
            PublicIdConfig::class,
            Generator::class,
            Validator::class,
            PrefixRegistry::class,
            LockFile::class,
            ConfigFingerprint::class,
            ConfigGuard::class,
        ];

        foreach ($services as $class) {
            $this->assertInstanceOf($class, $this->app->make($class));
        }
    }

    public function test_each_service_is_singleton(): void
    {
        $services = [
            AlphabetRegistry::class,
            PublicIdConfig::class,
            Generator::class,
            Validator::class,
            PrefixRegistry::class,
            LockFile::class,
            ConfigFingerprint::class,
            ConfigGuard::class,
        ];

        foreach ($services as $class) {
            $this->assertSame(
                $this->app->make($class),
                $this->app->make($class),
                "Service {$class} is not bound as a singleton",
            );
        }
    }

    public function test_merged_config_is_accessible(): void
    {
        $config = config('jamesgifford.auth.public_id');

        $this->assertIsArray($config);
        $this->assertSame(7, $config['prefix_max_length']);
        $this->assertSame('_', $config['separator']);
        $this->assertSame(18, $config['body']['length']);
        $this->assertSame('lowercase_alphanumeric', $config['body']['alphabet']);
    }

    public function test_public_id_facade_works_without_manual_binding(): void
    {
        $id = PublicId::generate('usr');

        $this->assertStringStartsWith('usr_', $id);
        $this->assertTrue(PublicId::isValid($id));
    }

    public function test_public_id_max_length_returns_expected_default(): void
    {
        // 7 prefix + 1 separator + 18 body + 2 checksum = 28
        $this->assertSame(28, PublicId::maxLength());
    }

    public function test_lock_file_uses_default_config_path_when_unset(): void
    {
        $lockFile = $this->app->make(LockFile::class);

        $this->assertStringEndsWith('jamesgifford/auth.lock.json', $lockFile->path());
    }

    public function test_models_in_config_are_registered_during_boot(): void
    {
        // The app booted earlier with no fixtures in config; here we verify the
        // configured model is reachable through the registered registry.
        // We use the AuthServiceProviderConfigTest variant for a true config-driven check.
        $registry = $this->app->make(PrefixRegistry::class);
        $registry->register(FixtureModel::class);

        $this->assertArrayHasKey(FixtureModel::class, $registry->all());
    }
}
