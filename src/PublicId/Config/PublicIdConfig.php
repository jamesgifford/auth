<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Config;

use JamesGifford\Auth\PublicId\Alphabet;
use JamesGifford\Auth\PublicId\AlphabetRegistry;
use JamesGifford\Auth\PublicId\Checksum\ChecksumStrategy;
use JamesGifford\Auth\PublicId\Exceptions\InvalidAlphabetException;
use JamesGifford\Auth\PublicId\Exceptions\InvalidPublicIdConfigException;

/**
 * Typed wrapper over the raw `jamesgifford.auth.public_id` config array.
 *
 * Validates the array eagerly at construction so that any misconfiguration
 * surfaces at boot rather than at first ID generation. Exposes typed
 * accessors and the locked-fingerprint subset of fields.
 *
 * Constructed by the service provider from `config('jamesgifford.auth.public_id')`.
 */
final class PublicIdConfig
{
    private const PREFIX_MAX_LENGTH_LIMIT = 64;

    private const BODY_LENGTH_LIMIT = 64;

    private const CHECKSUM_LENGTH_LIMIT = 16;

    private const TOTAL_MAX_LENGTH_CEILING = 128;

    private readonly int $prefixMaxLength;

    private readonly string $separator;

    private readonly int $bodyLength;

    private readonly string $bodyAlphabetConfigValue;

    private readonly Alphabet $bodyAlphabet;

    private readonly bool $checksumEnabled;

    private readonly int $checksumLengthRaw;

    private readonly string $checksumStrategy;

    private readonly ?string $lockFilePath;

    /** @var array<string, string> */
    private readonly array $prefixes;

    /** @var array<string, string> */
    private readonly array $customAlphabetPresets;

    /**
     * @param  array<string, mixed>  $config  the `public_id` config subarray
     *
     * @throws InvalidPublicIdConfigException on any validation failure;
     *                                        message names the offending key and value
     * @throws InvalidAlphabetException
     *                                  when `body.alphabet` resolves to an invalid raw alphabet
     */
    public function __construct(array $config, AlphabetRegistry $alphabetRegistry)
    {
        $this->prefixMaxLength = $this->validatePrefixMaxLength($config);
        $this->separator = $this->validateSeparator($config);

        [$bodyLength, $bodyAlphabetValue] = $this->validateBodyShape($config);
        $this->bodyLength = $bodyLength;
        $this->bodyAlphabetConfigValue = $bodyAlphabetValue;

        $this->customAlphabetPresets = $this->validateCustomAlphabetPresets($config);

        $effectiveRegistry = $this->customAlphabetPresets === []
            ? $alphabetRegistry
            : new AlphabetRegistry($this->customAlphabetPresets);

        $this->bodyAlphabet = $effectiveRegistry->resolve($this->bodyAlphabetConfigValue);

        if ($this->bodyAlphabet->contains($this->separator)) {
            throw InvalidPublicIdConfigException::forKey(
                'separator',
                sprintf(
                    "separator '%s' must not be a member of the body alphabet '%s'",
                    $this->separator,
                    $this->bodyAlphabet->toString(),
                ),
            );
        }

        [$checksumEnabled, $checksumLengthRaw, $checksumStrategy] = $this->validateChecksum($config);
        $this->checksumEnabled = $checksumEnabled;
        $this->checksumLengthRaw = $checksumLengthRaw;
        $this->checksumStrategy = $checksumStrategy;

        $effectiveChecksumLength = $this->checksumEnabled ? $this->checksumLengthRaw : 0;
        $totalMax = $this->prefixMaxLength + 1 + $this->bodyLength + $effectiveChecksumLength;
        if ($totalMax > self::TOTAL_MAX_LENGTH_CEILING) {
            throw InvalidPublicIdConfigException::forKey(
                'total_max_length',
                'sum of prefix_max_length + 1 + body.length + checksum.length is '
                .$totalMax.', which exceeds the ceiling of '.self::TOTAL_MAX_LENGTH_CEILING
            );
        }

        $this->lockFilePath = $this->validateLockFilePath($config);
        $this->prefixes = $this->validatePrefixes($config, $this->prefixMaxLength);
    }

    public function prefixMaxLength(): int
    {
        return $this->prefixMaxLength;
    }

    public function separator(): string
    {
        return $this->separator;
    }

    public function bodyLength(): int
    {
        return $this->bodyLength;
    }

    public function bodyAlphabet(): Alphabet
    {
        return $this->bodyAlphabet;
    }

    public function bodyAlphabetConfigValue(): string
    {
        return $this->bodyAlphabetConfigValue;
    }

    public function checksumEnabled(): bool
    {
        return $this->checksumEnabled;
    }

    public function checksumLength(): int
    {
        return $this->checksumEnabled ? $this->checksumLengthRaw : 0;
    }

    public function checksumStrategy(): string
    {
        return $this->checksumStrategy;
    }

    public function lockFilePath(): ?string
    {
        return $this->lockFilePath;
    }

    /**
     * @return array<string, string>
     */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    /**
     * @return array<string, string>
     */
    public function customAlphabetPresets(): array
    {
        return $this->customAlphabetPresets;
    }

    public function totalMaxLength(): int
    {
        return $this->prefixMaxLength + 1 + $this->bodyLength + $this->checksumLength();
    }

    /**
     * Locked-fingerprint subset, flat dot-notation keys, alphabetically sorted.
     *
     * @return array<string, mixed>
     */
    public function fingerprintFields(): array
    {
        $fields = [
            'body.alphabet' => $this->bodyAlphabetConfigValue,
            'body.length' => $this->bodyLength,
            'checksum.enabled' => $this->checksumEnabled,
            'checksum.length' => $this->checksumLength(),
            'checksum.strategy' => $this->checksumStrategy,
            'separator' => $this->separator,
        ];
        ksort($fields);

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validatePrefixMaxLength(array $config): int
    {
        if (! array_key_exists('prefix_max_length', $config)) {
            throw InvalidPublicIdConfigException::forKey('prefix_max_length', 'missing');
        }
        $value = $config['prefix_max_length'];
        if (! is_int($value)) {
            throw InvalidPublicIdConfigException::forKey(
                'prefix_max_length',
                'must be an integer; got '.get_debug_type($value),
            );
        }
        if ($value < 1 || $value > self::PREFIX_MAX_LENGTH_LIMIT) {
            throw InvalidPublicIdConfigException::forKey(
                'prefix_max_length',
                'must be an integer between 1 and '.self::PREFIX_MAX_LENGTH_LIMIT.'; got '.$value,
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateSeparator(array $config): string
    {
        if (! array_key_exists('separator', $config)) {
            throw InvalidPublicIdConfigException::forKey('separator', 'missing');
        }
        $value = $config['separator'];
        if (! is_string($value)) {
            throw InvalidPublicIdConfigException::forKey(
                'separator',
                'must be a string; got '.get_debug_type($value),
            );
        }
        if (strlen($value) !== 1) {
            throw InvalidPublicIdConfigException::forKey(
                'separator',
                "must be exactly one ASCII character; got '{$value}' (".strlen($value).' bytes)',
            );
        }
        if (mb_strlen($value) !== 1) {
            throw InvalidPublicIdConfigException::forKey(
                'separator',
                "must be exactly one ASCII character (multi-byte not supported); got '{$value}'",
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: int, 1: string}
     */
    private function validateBodyShape(array $config): array
    {
        if (! array_key_exists('body', $config) || ! is_array($config['body'])) {
            throw InvalidPublicIdConfigException::forKey('body', 'must be an array');
        }
        $body = $config['body'];

        if (! array_key_exists('length', $body)) {
            throw InvalidPublicIdConfigException::forKey('body.length', 'missing');
        }
        $length = $body['length'];
        if (! is_int($length)) {
            throw InvalidPublicIdConfigException::forKey(
                'body.length',
                'must be an integer; got '.get_debug_type($length),
            );
        }
        if ($length < 1 || $length > self::BODY_LENGTH_LIMIT) {
            throw InvalidPublicIdConfigException::forKey(
                'body.length',
                'must be an integer between 1 and '.self::BODY_LENGTH_LIMIT.'; got '.$length,
            );
        }

        if (! array_key_exists('alphabet', $body)) {
            throw InvalidPublicIdConfigException::forKey('body.alphabet', 'missing');
        }
        $alphabet = $body['alphabet'];
        if (! is_string($alphabet) || $alphabet === '') {
            throw InvalidPublicIdConfigException::forKey(
                'body.alphabet',
                'must be a non-empty string (preset name or raw alphabet); got '.get_debug_type($alphabet),
            );
        }

        return [$length, $alphabet];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: bool, 1: int, 2: string}
     */
    private function validateChecksum(array $config): array
    {
        if (! array_key_exists('checksum', $config) || ! is_array($config['checksum'])) {
            throw InvalidPublicIdConfigException::forKey('checksum', 'must be an array');
        }
        $checksum = $config['checksum'];

        if (! array_key_exists('enabled', $checksum)) {
            throw InvalidPublicIdConfigException::forKey('checksum.enabled', 'missing');
        }
        $enabled = $checksum['enabled'];
        if (! is_bool($enabled)) {
            throw InvalidPublicIdConfigException::forKey(
                'checksum.enabled',
                'must be a boolean; got '.get_debug_type($enabled),
            );
        }

        if (! array_key_exists('length', $checksum)) {
            throw InvalidPublicIdConfigException::forKey('checksum.length', 'missing');
        }
        $length = $checksum['length'];
        if (! is_int($length)) {
            throw InvalidPublicIdConfigException::forKey(
                'checksum.length',
                'must be an integer; got '.get_debug_type($length),
            );
        }
        if ($length < 0 || $length > self::CHECKSUM_LENGTH_LIMIT) {
            throw InvalidPublicIdConfigException::forKey(
                'checksum.length',
                'must be an integer between 0 and '.self::CHECKSUM_LENGTH_LIMIT.'; got '.$length,
            );
        }
        if ($enabled && $length < 1) {
            throw InvalidPublicIdConfigException::forKey(
                'checksum.length',
                'must be at least 1 when checksum.enabled is true; got '.$length,
            );
        }

        if (! array_key_exists('strategy', $checksum)) {
            throw InvalidPublicIdConfigException::forKey('checksum.strategy', 'missing');
        }
        $strategy = $checksum['strategy'];
        if (! is_string($strategy) || $strategy === '') {
            throw InvalidPublicIdConfigException::forKey(
                'checksum.strategy',
                'must be a non-empty class name; got '.get_debug_type($strategy),
            );
        }
        if (! class_exists($strategy)) {
            throw InvalidPublicIdConfigException::forKey(
                'checksum.strategy',
                "class '{$strategy}' does not exist"
            );
        }
        if (! is_a($strategy, ChecksumStrategy::class, true)) {
            throw InvalidPublicIdConfigException::forKey(
                'checksum.strategy',
                "class '{$strategy}' must implement ".ChecksumStrategy::class
            );
        }

        return [$enabled, $length, $strategy];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateLockFilePath(array $config): ?string
    {
        if (! array_key_exists('lock_file_path', $config)) {
            return null;
        }
        $value = $config['lock_file_path'];
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw InvalidPublicIdConfigException::forKey(
                'lock_file_path',
                'must be null or a non-empty string'
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function validatePrefixes(array $config, int $maxLength): array
    {
        if (! array_key_exists('prefixes', $config)) {
            return [];
        }
        $prefixes = $config['prefixes'];
        if (! is_array($prefixes)) {
            throw InvalidPublicIdConfigException::forKey('prefixes', 'must be an array');
        }

        $result = [];
        foreach ($prefixes as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw InvalidPublicIdConfigException::forKey(
                    'prefixes',
                    'keys must be non-empty strings (model class FQCNs)'
                );
            }
            if (! is_string($value)) {
                throw InvalidPublicIdConfigException::forKey(
                    "prefixes.{$key}",
                    'must be a string'
                );
            }
            if (preg_match('/^[a-z]+$/', $value) !== 1 || strlen($value) > $maxLength) {
                throw InvalidPublicIdConfigException::forKey(
                    "prefixes.{$key}",
                    "must be 1 to {$maxLength} lowercase ASCII letters"
                );
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function validateCustomAlphabetPresets(array $config): array
    {
        if (! array_key_exists('custom_alphabet_presets', $config)) {
            return [];
        }
        $presets = $config['custom_alphabet_presets'];
        if (! is_array($presets)) {
            throw InvalidPublicIdConfigException::forKey(
                'custom_alphabet_presets',
                'must be an array'
            );
        }

        $result = [];
        foreach ($presets as $name => $characters) {
            if (! is_string($name) || $name === '') {
                throw InvalidPublicIdConfigException::forKey(
                    'custom_alphabet_presets',
                    'keys must be non-empty strings'
                );
            }
            if (! is_string($characters) || $characters === '') {
                throw InvalidPublicIdConfigException::forKey(
                    "custom_alphabet_presets.{$name}",
                    'must be a non-empty string'
                );
            }
            $result[$name] = $characters;
        }

        return $result;
    }
}
