<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature;

use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Generator;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\PublicId\Validator;
use JamesGifford\Auth\Tests\Support\PublicIdConfigFactory;
use JamesGifford\Auth\Tests\TestCase;

class PublicIdFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = PublicIdConfigFactory::default();
        $this->app->instance(PublicIdConfig::class, $config);
        $this->app->instance(Generator::class, new Generator($config));
        $this->app->instance(Validator::class, new Validator($config));
    }

    public function test_validate_with_expected_prefix(): void
    {
        $id = PublicId::generate('usr');

        $this->assertTrue(PublicId::validate($id, 'usr')->isValid());
        $this->assertFalse(PublicId::validate($id, 'proj')->isValid());
    }

    public function test_max_length_returns_expected_total(): void
    {
        // 7 prefix + 1 separator + 18 body + 2 checksum = 28
        $this->assertSame(28, PublicId::maxLength());
    }

    public function test_prefix_of_returns_prefix_for_valid_id(): void
    {
        $id = PublicId::generate('proj');

        $this->assertSame('proj', PublicId::prefixOf($id));
    }

    public function test_prefix_of_returns_null_for_invalid_input(): void
    {
        $this->assertNull(PublicId::prefixOf('garbage'));
    }
}
