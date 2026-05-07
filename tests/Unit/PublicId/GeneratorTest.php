<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Unit\PublicId;

use Progravity\Auth\PublicId\Checksum\NullChecksum;
use Progravity\Auth\PublicId\Exceptions\InvalidPrefixException;
use Progravity\Auth\PublicId\Generator;
use Progravity\Auth\Tests\Support\PublicIdConfigFactory;
use Progravity\Auth\Tests\TestCase;

class GeneratorTest extends TestCase
{
    public function test_generated_id_has_expected_overall_structure(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);

        $id = $generator->generate('usr');

        // 3 prefix + 1 separator + 18 body + 2 checksum
        $this->assertSame(24, strlen($id));
        $this->assertStringStartsWith('usr_', $id);
    }

    public function test_prefix_is_preserved_verbatim(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);

        $id = $generator->generate('proj');

        $this->assertStringStartsWith('proj_', $id);
    }

    public function test_body_length_matches_config(): void
    {
        $config = PublicIdConfigFactory::default(['body' => ['length' => 12]]);
        $generator = new Generator($config);

        $id = $generator->generate('a');
        $remainder = substr($id, 2); // 'a' + '_'

        // 12 body + 2 checksum
        $this->assertSame(14, strlen($remainder));
    }

    public function test_body_chars_are_all_in_alphabet(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);
        $alphabet = $config->bodyAlphabet();

        $body = $generator->generateBody();

        $this->assertSame($config->bodyLength(), strlen($body));
        foreach (mb_str_split($body) as $char) {
            $this->assertTrue($alphabet->contains($char), "char '{$char}' not in alphabet");
        }
    }

    public function test_checksum_length_matches_config_when_enabled(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);

        $id = $generator->generate('usr');
        $checksum = substr($id, -2);

        $this->assertSame(2, strlen($checksum));
        foreach (mb_str_split($checksum) as $char) {
            $this->assertTrue($config->bodyAlphabet()->contains($char));
        }
    }

    public function test_disabled_checksum_produces_id_without_checksum_suffix(): void
    {
        $config = PublicIdConfigFactory::default([
            'checksum' => [
                'enabled' => false,
                'length' => 0,
                'strategy' => NullChecksum::class,
            ],
        ]);
        $generator = new Generator($config);

        $id = $generator->generate('usr');

        // 3 prefix + 1 separator + 18 body
        $this->assertSame(22, strlen($id));
    }

    public function test_multiple_generated_ids_are_unique(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $generator->generate('x');
        }

        $this->assertCount(100, array_unique($ids));
    }

    public function test_generate_throws_for_empty_prefix(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);

        $this->expectException(InvalidPrefixException::class);
        $generator->generate('');
    }

    public function test_generate_throws_for_uppercase_prefix(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);

        $this->expectException(InvalidPrefixException::class);
        $generator->generate('USR');
    }

    public function test_generate_throws_for_digits_in_prefix(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);

        $this->expectException(InvalidPrefixException::class);
        $generator->generate('usr1');
    }

    public function test_generate_throws_for_special_characters_in_prefix(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);

        $this->expectException(InvalidPrefixException::class);
        $generator->generate('us-r');
    }

    public function test_generate_throws_when_prefix_exceeds_max_length(): void
    {
        $config = PublicIdConfigFactory::default(['prefix_max_length' => 3]);
        $generator = new Generator($config);

        $this->expectException(InvalidPrefixException::class);
        $generator->generate('abcd');
    }

    public function test_generate_body_returns_string_of_configured_length(): void
    {
        $config = PublicIdConfigFactory::default(['body' => ['length' => 24]]);
        $generator = new Generator($config);

        $this->assertSame(24, strlen($generator->generateBody()));
    }

    public function test_compute_checksum_known_value(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);

        // hello → es with default lowercase_alphanumeric, length 2
        // (h=7*1) + (e=4*2) + (l=11*3) + (l=11*4) + (o=14*5)
        //   = 7 + 8 + 33 + 44 + 70 = 162; 162 mod 1296 = 162;
        //   162 = 4*36 + 18 → charAt(4) + charAt(18) = "es"
        $this->assertSame('es', $generator->computeChecksum('hello'));
    }

    public function test_works_with_null_checksum_strategy(): void
    {
        $config = PublicIdConfigFactory::default([
            'checksum' => [
                'enabled' => false,
                'length' => 0,
                'strategy' => NullChecksum::class,
            ],
        ]);
        $generator = new Generator($config);

        $id = $generator->generate('usr');
        $checksum = $generator->computeChecksum($generator->generateBody());

        $this->assertSame('', $checksum);
        $this->assertSame(22, strlen($id)); // no trailing checksum
    }

    public function test_works_with_non_default_alphabet(): void
    {
        $config = PublicIdConfigFactory::default([
            'body' => ['alphabet' => 'crockford'],
        ]);
        $generator = new Generator($config);

        $id = $generator->generate('usr');
        $remainder = substr($id, 4); // 'usr_'

        // crockford = 0123456789ABCDEFGHJKMNPQRSTVWXYZ
        $this->assertMatchesRegularExpression(
            '/^[0-9A-HJ-KM-NP-TV-Z]+$/',
            $remainder,
        );
    }
}
