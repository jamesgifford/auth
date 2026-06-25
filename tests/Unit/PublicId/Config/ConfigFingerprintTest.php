<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\PublicId\Config;

use JamesGifford\Auth\PublicId\AlphabetRegistry;
use JamesGifford\Auth\PublicId\Checksum\NullChecksum;
use JamesGifford\Auth\PublicId\Checksum\PositionalSumChecksum;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConfigFingerprintTest extends TestCase
{
    public function test_identical_configs_produce_identical_fingerprints(): void
    {
        $a = $this->fingerprint($this->baseConfig());
        $b = $this->fingerprint($this->baseConfig());

        $this->assertSame($a, $b);
    }

    /**
     * Each row provides a list of base mutations and a list of changed
     * mutations. A mutation is [keyPath, value] applied to baseConfig().
     *
     * @return array<string, array{0: array<int, array{0: array<int, string>, 1: mixed}>, 1: array<int, array{0: array<int, string>, 1: mixed}>}>
     */
    public static function provideLockedFieldChanges(): array
    {
        return [
            'body.length' => [
                [],
                [[['body', 'length'], 16]],
            ],
            'body.alphabet' => [
                [],
                [[['body', 'alphabet'], 'crockford'], [['separator'], '-']],
            ],
            'checksum.enabled' => [
                [],
                [[['checksum', 'enabled'], false], [['checksum', 'strategy'], NullChecksum::class]],
            ],
            'checksum.length' => [
                [],
                [[['checksum', 'length'], 4]],
            ],
            'checksum.strategy' => [
                [[['checksum', 'enabled'], false], [['checksum', 'strategy'], NullChecksum::class]],
                [[['checksum', 'enabled'], false], [['checksum', 'strategy'], PositionalSumChecksum::class], [['checksum', 'enabled'], true], [['checksum', 'length'], 2]],
            ],
            'separator' => [
                [],
                [[['separator'], '-']],
            ],
        ];
    }

    /**
     * @param  array<int, array{0: array<int, string>, 1: mixed}>  $baseMutations
     * @param  array<int, array{0: array<int, string>, 1: mixed}>  $changedMutations
     */
    #[DataProvider('provideLockedFieldChanges')]
    public function test_changing_locked_field_changes_fingerprint(array $baseMutations, array $changedMutations): void
    {
        $base = $this->applyMutations($this->baseConfig(), $baseMutations);
        $changed = $this->applyMutations($this->baseConfig(), $changedMutations);

        $this->assertNotSame($this->fingerprint($base), $this->fingerprint($changed));
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: mixed}>
     */
    public static function provideNonLockedFieldChanges(): array
    {
        return [
            'prefix_max_length' => [['prefix_max_length'], 16],
            'prefixes' => [['prefixes'], ['App\\Models\\Workspace' => 'wsp']],
            'custom_alphabet_presets' => [['custom_alphabet_presets'], ['unused' => 'xyz']],
        ];
    }

    /**
     * @param  array<int, string>  $keyPath
     */
    #[DataProvider('provideNonLockedFieldChanges')]
    public function test_changing_non_locked_field_does_not_change_fingerprint(array $keyPath, mixed $value): void
    {
        $base = $this->baseConfig();
        $changed = $this->applyMutations($this->baseConfig(), [[$keyPath, $value]]);

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

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, array{0: array<int, string>, 1: mixed}>  $mutations
     * @return array<string, mixed>
     */
    private function applyMutations(array $config, array $mutations): array
    {
        foreach ($mutations as [$keyPath, $value]) {
            $ref = &$config;
            foreach ($keyPath as $i => $key) {
                if ($i === count($keyPath) - 1) {
                    $ref[$key] = $value;
                } else {
                    $ref = &$ref[$key];
                }
            }
            unset($ref);
        }

        return $config;
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
