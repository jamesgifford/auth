<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\PublicId;

use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\PublicId\Exceptions\PrefixCollisionException;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModelCollisionA;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModelCollisionB;
use JamesGifford\Auth\Tests\TestCase;
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
        $app['config']->set('jamesgifford.auth.public_id.prefixes', [
            FixtureModelCollisionA::class => 'col',
            FixtureModelCollisionB::class => 'col',
        ]);
    }
}
