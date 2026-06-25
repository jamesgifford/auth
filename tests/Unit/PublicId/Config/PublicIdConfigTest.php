<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\PublicId\Config;

use JamesGifford\Auth\PublicId\AlphabetRegistry;
use JamesGifford\Auth\PublicId\Checksum\NullChecksum;
use JamesGifford\Auth\PublicId\Checksum\PositionalSumChecksum;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Exceptions\InvalidAlphabetException;
use JamesGifford\Auth\PublicId\Exceptions\InvalidPublicIdConfigException;
use JamesGifford\Auth\Tests\TestCase;
use stdClass;

class PublicIdConfigTest extends TestCase
{
    public function test_constructs_with_full_valid_config(): void
    {
        $config = new PublicIdConfig($this->baseConfig(), $this->registry());

        $this->assertSame(7, $config->prefixMaxLength());
        $this->assertSame('_', $config->separator());
        $this->assertSame(18, $config->bodyLength());
        $this->assertSame('lowercase_alphanumeric', $config->bodyAlphabetConfigValue());
        $this->assertSame(36, $config->bodyAlphabet()->size());
        $this->assertTrue($config->checksumEnabled());
        $this->assertSame(2, $config->checksumLength());
        $this->assertSame(PositionalSumChecksum::class, $config->checksumStrategy());
        $this->assertNull($config->lockFilePath());
        $this->assertSame([], $config->prefixes());
        $this->assertSame([], $config->customAlphabetPresets());
    }

    public function test_total_max_length_sums_components(): void
    {
        $config = new PublicIdConfig($this->baseConfig(), $this->registry());

        // 7 + 1 + 18 + 2 = 28
        $this->assertSame(28, $config->totalMaxLength());
    }

    public function test_prefix_max_length_zero_throws(): void
    {
        $base = $this->baseConfig();
        $base['prefix_max_length'] = 0;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('prefix_max_length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_prefix_max_length_negative_throws(): void
    {
        $base = $this->baseConfig();
        $base['prefix_max_length'] = -1;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('prefix_max_length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_prefix_max_length_too_large_throws(): void
    {
        $base = $this->baseConfig();
        $base['prefix_max_length'] = 65;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('prefix_max_length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_separator_empty_throws(): void
    {
        $base = $this->baseConfig();
        $base['separator'] = '';

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('separator');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_prefixes_map_empty_value_throws(): void
    {
        // A configured prefix must be at least one character.
        $base = $this->baseConfig();
        $base['prefixes'] = ['App\\Models\\Thing' => ''];

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('must be 1 to 7 lowercase ASCII letters');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_separator_two_chars_throws(): void
    {
        $base = $this->baseConfig();
        $base['separator'] = '__';

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('separator');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_separator_inside_alphabet_throws(): void
    {
        $base = $this->baseConfig();
        $base['body']['alphabet'] = 'lowercase_alpha';
        $base['separator'] = 'a';

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('separator');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_body_length_zero_throws(): void
    {
        $base = $this->baseConfig();
        $base['body']['length'] = 0;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('body.length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_body_length_negative_throws(): void
    {
        $base = $this->baseConfig();
        $base['body']['length'] = -1;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('body.length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_body_length_too_large_throws(): void
    {
        $base = $this->baseConfig();
        $base['body']['length'] = 65;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('body.length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_body_alphabet_resolves_built_in_preset(): void
    {
        $base = $this->baseConfig();
        $base['body']['alphabet'] = 'crockford';
        $base['separator'] = '-';

        $config = new PublicIdConfig($base, $this->registry());

        $this->assertSame(32, $config->bodyAlphabet()->size());
    }

    public function test_body_alphabet_resolves_raw_alphabet_string(): void
    {
        $base = $this->baseConfig();
        $base['body']['alphabet'] = 'xyz123';

        $config = new PublicIdConfig($base, $this->registry());

        $this->assertSame('xyz123', $config->bodyAlphabet()->toString());
    }

    public function test_body_alphabet_with_duplicates_propagates_invalid_alphabet_exception(): void
    {
        $base = $this->baseConfig();
        $base['body']['alphabet'] = 'aabbc';

        $this->expectException(InvalidAlphabetException::class);
        new PublicIdConfig($base, $this->registry());
    }

    public function test_checksum_enabled_must_be_bool(): void
    {
        $base = $this->baseConfig();
        $base['checksum']['enabled'] = 'yes';

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('checksum.enabled');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_checksum_length_negative_throws(): void
    {
        $base = $this->baseConfig();
        $base['checksum']['length'] = -1;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('checksum.length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_checksum_length_exceeds_limit_throws(): void
    {
        $base = $this->baseConfig();
        $base['checksum']['length'] = 17;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('checksum.length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_checksum_length_zero_with_enabled_true_throws(): void
    {
        $base = $this->baseConfig();
        $base['checksum']['enabled'] = true;
        $base['checksum']['length'] = 0;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('checksum.length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_checksum_disabled_normalizes_length_to_zero(): void
    {
        $base = $this->baseConfig();
        $base['checksum']['enabled'] = false;
        $base['checksum']['length'] = 5;
        $base['checksum']['strategy'] = NullChecksum::class;

        $config = new PublicIdConfig($base, $this->registry());

        $this->assertFalse($config->checksumEnabled());
        $this->assertSame(0, $config->checksumLength());
    }

    public function test_checksum_strategy_non_existent_class_throws(): void
    {
        $base = $this->baseConfig();
        $base['checksum']['strategy'] = 'No\\Such\\Class';

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('checksum.strategy');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_checksum_strategy_class_not_implementing_interface_throws(): void
    {
        $base = $this->baseConfig();
        $base['checksum']['strategy'] = stdClass::class;

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('checksum.strategy');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_prefixes_with_invalid_uppercase_value_throws(): void
    {
        $base = $this->baseConfig();
        $base['prefixes'] = ['App\\Models\\Workspace' => 'WSP'];

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('App\\Models\\Workspace');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_prefixes_with_non_letter_value_throws(): void
    {
        $base = $this->baseConfig();
        $base['prefixes'] = ['App\\Models\\Workspace' => 'wsp1'];

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('App\\Models\\Workspace');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_prefixes_value_too_long_throws(): void
    {
        $base = $this->baseConfig();
        $base['prefix_max_length'] = 3;
        $base['prefixes'] = ['App\\Models\\Workspace' => 'wxyz'];

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('App\\Models\\Workspace');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_prefixes_valid_pass_through(): void
    {
        $base = $this->baseConfig();
        $base['prefixes'] = [
            'App\\Models\\Workspace' => 'wsp',
            'App\\Models\\Project' => 'prj',
        ];

        $config = new PublicIdConfig($base, $this->registry());

        $this->assertSame([
            'App\\Models\\Workspace' => 'wsp',
            'App\\Models\\Project' => 'prj',
        ], $config->prefixes());
    }

    public function test_custom_alphabet_presets_resolvable_via_body_alphabet(): void
    {
        $base = $this->baseConfig();
        $base['body']['alphabet'] = 'my_custom';
        $base['custom_alphabet_presets'] = [
            'my_custom' => 'abcdefghjkmnpqrstuvwxyz',
        ];

        $config = new PublicIdConfig($base, $this->registry());

        $this->assertSame('my_custom', $config->bodyAlphabetConfigValue());
        $this->assertSame(23, $config->bodyAlphabet()->size());
        $this->assertSame(['my_custom' => 'abcdefghjkmnpqrstuvwxyz'], $config->customAlphabetPresets());
    }

    public function test_total_max_length_ceiling_enforced(): void
    {
        $base = $this->baseConfig();
        $base['prefix_max_length'] = 64;
        $base['body']['length'] = 64;
        $base['checksum']['length'] = 16;
        // 64 + 1 + 64 + 16 = 145 > 128

        $this->expectException(InvalidPublicIdConfigException::class);
        $this->expectExceptionMessage('total_max_length');
        new PublicIdConfig($base, $this->registry());
    }

    public function test_fingerprint_fields_returns_locked_subset_alphabetically_sorted(): void
    {
        $config = new PublicIdConfig($this->baseConfig(), $this->registry());

        $fields = $config->fingerprintFields();

        $expectedKeys = [
            'body.alphabet',
            'body.length',
            'checksum.enabled',
            'checksum.length',
            'checksum.strategy',
            'separator',
        ];
        $this->assertSame($expectedKeys, array_keys($fields));

        $this->assertSame('lowercase_alphanumeric', $fields['body.alphabet']);
        $this->assertSame(18, $fields['body.length']);
        $this->assertTrue($fields['checksum.enabled']);
        $this->assertSame(2, $fields['checksum.length']);
        $this->assertSame(PositionalSumChecksum::class, $fields['checksum.strategy']);
        $this->assertSame('_', $fields['separator']);
    }

    public function test_fingerprint_fields_excludes_non_locked_keys(): void
    {
        $config = new PublicIdConfig($this->baseConfig(), $this->registry());

        $fields = $config->fingerprintFields();

        $this->assertArrayNotHasKey('prefix_max_length', $fields);
        $this->assertArrayNotHasKey('prefixes', $fields);
        $this->assertArrayNotHasKey('lock_file_path', $fields);
        $this->assertArrayNotHasKey('custom_alphabet_presets', $fields);
    }

    private function registry(): AlphabetRegistry
    {
        return new AlphabetRegistry;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'prefix_max_length' => 7,
            'separator' => '_',
            'body' => [
                'length' => 18,
                'alphabet' => 'lowercase_alphanumeric',
            ],
            'checksum' => [
                'enabled' => true,
                'length' => 2,
                'strategy' => PositionalSumChecksum::class,
            ],
            'lock_file_path' => null,
            'prefixes' => [],
            'custom_alphabet_presets' => [],
        ];
    }
}
