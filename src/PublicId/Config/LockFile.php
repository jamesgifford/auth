<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Config;

use DateTimeImmutable;
use DateTimeZone;
use JamesGifford\Auth\PublicId\Exceptions\IncompleteLockFileException;
use JamesGifford\Auth\PublicId\Exceptions\LockFileWriteException;
use JamesGifford\Auth\PublicId\Exceptions\MalformedLockFileException;
use JamesGifford\Auth\PublicId\Exceptions\MissingLockFileException;
use JsonException;

/**
 * Reads, writes, and deletes the public_id lock file on disk.
 *
 * The lock file is a small JSON document recording the fingerprint of the
 * configuration at setup time. It is the single source of truth that the
 * boot guard compares against, so callers should not parse it manually —
 * use this class.
 */
final class LockFile
{
    private const FORMAT_VERSION = 1;

    private const REQUIRED_KEYS = ['version', 'locked_at', 'fingerprint', 'config'];

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Read and parse the lock file.
     *
     * @throws MissingLockFileException when the file does not exist
     * @throws MalformedLockFileException when the file content is not valid JSON
     * @throws IncompleteLockFileException when required keys are missing or wrongly typed
     */
    public function read(): LockFileContents
    {
        if (! $this->exists()) {
            throw MissingLockFileException::forPath($this->path);
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw MalformedLockFileException::forPath($this->path, 'unable to read file contents');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw MalformedLockFileException::forPath($this->path, $e->getMessage());
        }

        if (! is_array($data)) {
            throw MalformedLockFileException::forPath(
                $this->path,
                'top-level value is not a JSON object',
            );
        }

        $missing = [];
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                $missing[] = $key;
            }
        }

        $typed = [];
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $expectsInt = $key === 'version';
            $expectsArray = $key === 'config';
            $value = $data[$key];

            $valid = $expectsInt
                ? is_int($value)
                : ($expectsArray ? is_array($value) : is_string($value));

            if (! $valid) {
                $typed[] = $key;
            }
        }

        if ($missing !== [] || $typed !== []) {
            throw IncompleteLockFileException::forPath(
                $this->path,
                array_values(array_unique(array_merge($missing, $typed))),
            );
        }

        return new LockFileContents(
            $data['version'],
            $data['locked_at'],
            $data['fingerprint'],
            $data['config'],
        );
    }

    /**
     * Write the lock file with the given config snapshot and fingerprint.
     *
     * @throws LockFileWriteException on directory creation, JSON encoding, or write failure
     */
    public function write(PublicIdConfig $config, string $fingerprint): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw LockFileWriteException::forPath(
                    $this->path,
                    "unable to create directory '{$directory}'",
                );
            }
        }

        $payload = [
            'version' => self::FORMAT_VERSION,
            'locked_at' => $this->nowIso8601Utc(),
            'fingerprint' => $fingerprint,
            'config' => [
                'separator' => $config->separator(),
                'body' => [
                    'length' => $config->bodyLength(),
                    'alphabet' => $config->bodyAlphabetConfigValue(),
                ],
                'checksum' => [
                    'enabled' => $config->checksumEnabled(),
                    'length' => $config->checksumLength(),
                    'strategy' => $config->checksumStrategy(),
                ],
            ],
        ];

        try {
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $e) {
            throw LockFileWriteException::forPath(
                $this->path,
                'failed to encode payload as JSON: '.$e->getMessage(),
                $e,
            );
        }

        $bytes = @file_put_contents($this->path, $json.PHP_EOL);
        if ($bytes === false) {
            throw LockFileWriteException::forPath($this->path, 'file_put_contents returned false');
        }
    }

    /**
     * Delete the lock file. No-op when the file does not exist.
     *
     * @throws LockFileWriteException when an existing file cannot be unlinked
     */
    public function delete(): void
    {
        if (! $this->exists()) {
            return;
        }
        if (! @unlink($this->path)) {
            throw LockFileWriteException::forPath($this->path, 'unlink failed');
        }
    }

    private function nowIso8601Utc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
