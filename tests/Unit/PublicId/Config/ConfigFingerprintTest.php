<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\PublicId\Config;

use JamesGifford\Auth\PublicId\AlphabetRegistry;
use JamesGifford\Auth\PublicId\Checksum\NullChecksum;
use JamesGifford\Auth\PublicId\Checksum\PositionalSumChecksum;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\Tests\TestCase;

class ConfigFingerprintTest extends TestCase
{
    public function test_identical_configs_produce_identical_fingerprints(): void
    {
        $a = $this->fingerprint($this->baseConfig());
        $b = $this->fingerprint($this->baseConfig());

        $this->assertSame($a, $b);
    }

    public function test_changing_body_length_changes_fingerprint(): void
    {
        $base = $this->baseConfig();
        $changed = $this->baseConfig();
        $changed['body']['length'] = 16;

        $this->assertNotSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    public function test_changing_body_alphabet_changes_fingerprint(): void
    {
        $base = $this->baseConfig();
        $changed = $this->baseConfig();
        $changed['body']['alphabet'] = 'crockford';
        $changed['separator'] = '-';

        $this->assertNotSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    public function test_changing_checksum_enabled_changes_fingerprint(): void
    {
        $base = $this->baseConfig();
        $changed = $this->baseConfig();
        $changed['checksum']['enabled'] = false;
        $changed['checksum']['strategy'] = NullChecksum::class;

        $this->assertNotSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    public function test_changing_checksum_length_changes_fingerprint(): void
    {
        $base = $this->baseConfig();
        $changed = $this->baseConfig();
        $changed['checksum']['length'] = 4;

        $this->assertNotSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    public function test_changing_checksum_strategy_changes_fingerprint(): void
    {
        $base = $this->baseConfig();
        $base['checksum']['enabled'] = false;
        $base['checksum']['strategy'] = NullChecksum::class;

        $changed = $base;
        $changed['checksum']['strategy'] = PositionalSumChecksum::class;
        $changed['checksum']['enabled'] = true;
        $changed['checksum']['length'] = 2;

        $this->assertNotSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    public function test_changing_separator_changes_fingerprint(): void
    {
        $base = $this->baseConfig();
        $changed = $this->baseConfig();
        $changed['separator'] = '-';

        $this->assertNotSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    public function test_changing_prefix_max_length_does_not_change_fingerprint(): void
    {
        $base = $this->baseConfig();
        $changed = $this->baseConfig();
        $changed['prefix_max_length'] = 16;

        $this->assertSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    public function test_changing_prefixes_does_not_change_fingerprint(): void
    {
        $base = $this->baseConfig();
        $changed = $this->baseConfig();
        $changed['prefixes'] = ['App\\Models\\Workspace' => 'wsp'];

        $this->assertSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    public function test_unused_custom_alphabet_preset_does_not_change_fingerprint(): void
    {
        $base = $this->baseConfig();
        $changed = $this->baseConfig();
        $changed['custom_alphabet_presets'] = ['unused' => 'xyz'];

        $this->assertSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    public function test_fingerprint_format_is_sha256_prefixed_hex(): void
    {
        $fingerprint = $this->fingerprint($this->baseConfig());

        $this->assertStringStartsWith('sha256:', $fingerprint);
        $hex = substr($fingerprint, 7);
        $this->assertSame(64, strlen($hex));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hex);
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

    private function fingerprint(array $configArray): string
    {
        $config = new PublicIdConfig($configArray, $this->registry());

        return (new ConfigFingerprint)->compute($config);
    }
}
