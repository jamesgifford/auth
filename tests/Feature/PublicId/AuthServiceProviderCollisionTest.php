<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\PublicId;

use Illuminate\Database\Eloquent\Model;
use Progravity\Auth\PublicId\Exceptions\PrefixCollisionException;
use Progravity\Auth\Tests\Support\Fixtures\FixtureModelCollisionA;
use Progravity\Auth\Tests\Support\Fixtures\FixtureModelCollisionB;
use Progravity\Auth\Tests\TestCase;
use Throwable;

class AuthServiceProviderCollisionTest extends TestCase
{
    private ?Throwable $bootException = null;

    protected function setUp(): void
    {
        Model::clearBootedModels();

        try {
            parent::setUp();
        } catch (Throwable $e) {
            $this->bootException = $e;
        }
    }

    public function test_collision_in_config_throws_during_boot(): void
    {
        $this->assertInstanceOf(PrefixCollisionException::class, $this->bootException);
        $this->assertStringContainsString("'col'", $this->bootException->getMessage());
        $this->assertStringContainsString(FixtureModelCollisionA::class, $this->bootException->getMessage());
        $this->assertStringContainsString(FixtureModelCollisionB::class, $this->bootException->getMessage());
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('progravity.auth.public_id.prefixes', [
            FixtureModelCollisionA::class => 'col',
            FixtureModelCollisionB::class => 'col',
        ]);
    }
}
