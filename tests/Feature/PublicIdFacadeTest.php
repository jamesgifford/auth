<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature;

use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Generator;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\PublicId\ValidationResult;
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

    public function test_generate_returns_valid_looking_id(): void
    {
        $id = PublicId::generate('usr');

        $this->assertStringStartsWith('usr_', $id);
        $this->assertSame(24, strlen($id));
    }

    public function test_validate_returns_valid_result_for_generated_id(): void
    {
        $id = PublicId::generate('usr');

        $result = PublicId::validate($id);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isValid());
        $this->assertSame('usr', $result->prefix);
    }

    public function test_validate_with_expected_prefix(): void
    {
        $id = PublicId::generate('usr');

        $this->assertTrue(PublicId::validate($id, 'usr')->isValid());
        $this->assertFalse(PublicId::validate($id, 'proj')->isValid());
    }

    public function test_is_valid_returns_true_for_generated_id(): void
    {
        $id = PublicId::generate('usr');

        $this->assertTrue(PublicId::isValid($id));
    }

    public function test_is_valid_returns_false_for_garbage(): void
    {
        $this->assertFalse(PublicId::isValid('garbage'));
    }

    public function test_parse_returns_valid_result_for_generated_id(): void
    {
        $id = PublicId::generate('usr');

        $result = PublicId::parse($id);

        $this->assertTrue($result->isValid());
        $this->assertSame('usr', $result->prefix);
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

    public function test_prefix_of_does_not_throw_on_garbage_input(): void
    {
        // sanity: the call returns null rather than throwing
        $result = PublicId::prefixOf('!!!');

        $this->assertNull($result);
    }
}
