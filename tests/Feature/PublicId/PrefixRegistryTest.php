<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\PublicId;

use Illuminate\Database\Eloquent\Model;
use Progravity\Auth\PublicId\Exceptions\InvalidPrefixException;
use Progravity\Auth\PublicId\Exceptions\PrefixCollisionException;
use Progravity\Auth\PublicId\Exceptions\UnregisteredModelException;
use Progravity\Auth\PublicId\PrefixRegistry;
use Progravity\Auth\Tests\Support\Fixtures\FixtureModel;
use Progravity\Auth\Tests\Support\Fixtures\FixtureModelBadPrefix;
use Progravity\Auth\Tests\Support\Fixtures\FixtureModelCollisionA;
use Progravity\Auth\Tests\Support\Fixtures\FixtureModelCollisionB;
use Progravity\Auth\Tests\Support\Fixtures\FixtureModelNoTrait;
use Progravity\Auth\Tests\Support\Fixtures\FixtureModelWithoutOverride;
use Progravity\Auth\Tests\Support\PublicIdConfigFactory;
use Progravity\Auth\Tests\TestCase;

class PrefixRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::clearBootedModels();
    }

    public function test_prefix_for_returns_value_from_trait_override(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default());

        $this->assertSame('fix', $registry->prefixFor(FixtureModel::class));
    }

    public function test_prefix_for_returns_value_from_config_when_no_override(): void
    {
        $config = PublicIdConfigFactory::default([
            'prefixes' => [
                FixtureModelWithoutOverride::class => 'wto',
            ],
        ]);
        $registry = new PrefixRegistry($config);

        $this->assertSame('wto', $registry->prefixFor(FixtureModelWithoutOverride::class));
    }

    public function test_prefix_for_returns_value_from_config_for_model_without_trait(): void
    {
        $config = PublicIdConfigFactory::default([
            'prefixes' => [
                FixtureModelNoTrait::class => 'nnt',
            ],
        ]);
        $registry = new PrefixRegistry($config);

        $this->assertSame('nnt', $registry->prefixFor(FixtureModelNoTrait::class));
    }

    public function test_prefix_for_throws_when_no_override_and_no_config(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default());

        $this->expectException(UnregisteredModelException::class);
        $registry->prefixFor(FixtureModelWithoutOverride::class);
    }

    public function test_prefix_for_throws_invalid_prefix_when_override_returns_invalid_value(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default());

        $this->expectException(InvalidPrefixException::class);
        $registry->prefixFor(FixtureModelBadPrefix::class);
    }

    public function test_prefix_for_caches_results(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default());

        $first = $registry->prefixFor(FixtureModel::class);
        $second = $registry->prefixFor(FixtureModel::class);

        $this->assertSame($first, $second);
        $this->assertArrayHasKey(FixtureModel::class, $registry->all());
    }

    public function test_register_populates_registry(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default());

        $registry->register(FixtureModel::class);

        $this->assertSame(['fix' => FixtureModel::class], array_flip($registry->all()));
    }

    public function test_all_reflects_registered_models(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default([
            'prefixes' => [
                FixtureModelNoTrait::class => 'nnt',
            ],
        ]));

        $registry->register(FixtureModel::class);
        $registry->register(FixtureModelNoTrait::class);

        $this->assertSame(
            [
                FixtureModel::class => 'fix',
                FixtureModelNoTrait::class => 'nnt',
            ],
            $registry->all(),
        );
    }

    public function test_model_for_returns_class_claiming_prefix(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default());

        $registry->register(FixtureModel::class);

        $this->assertSame(FixtureModel::class, $registry->modelFor('fix'));
    }

    public function test_model_for_returns_null_when_unknown(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default());

        $this->assertNull($registry->modelFor('unknown'));
    }

    public function test_assert_no_collisions_passes_when_unique(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default());

        $registry->register(FixtureModel::class);

        $registry->assertNoCollisions();

        $this->assertTrue(true); // reached without throwing
    }

    public function test_assert_no_collisions_throws_listing_both_models(): void
    {
        $registry = new PrefixRegistry(PublicIdConfigFactory::default());

        $registry->register(FixtureModelCollisionA::class);
        $registry->register(FixtureModelCollisionB::class);

        try {
            $registry->assertNoCollisions();
            $this->fail('Expected PrefixCollisionException');
        } catch (PrefixCollisionException $e) {
            $this->assertStringContainsString("'col'", $e->getMessage());
            $this->assertStringContainsString(FixtureModelCollisionA::class, $e->getMessage());
            $this->assertStringContainsString(FixtureModelCollisionB::class, $e->getMessage());
        }
    }
}
