<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Config;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Progravity\Auth\PublicId\Exceptions\CorruptLockFileException;
use Progravity\Auth\PublicId\Exceptions\LockFileWriteException;

final class LockFile
{
    private const FORMAT_VERSION = 1;

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function read(): LockFileContents
    {
        if (! $this->exists()) {
            throw new CorruptLockFileException(
                "Lock file does not exist: {$this->path}"
            );
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new CorruptLockFileException(
                "Unable to read lock file: {$this->path}"
            );
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CorruptLockFileException(
                "Lock file contains invalid JSON: {$this->path}",
                0,
                $e
            );
        }

        if (! is_array($data)) {
            throw new CorruptLockFileException(
                "Lock file does not contain a JSON object: {$this->path}"
            );
        }

        foreach (['version', 'locked_at', 'fingerprint', 'config'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new CorruptLockFileException(
                    "Lock file missing required key '{$key}': {$this->path}"
                );
            }
        }

        if (! is_int($data['version'])) {
            throw new CorruptLockFileException(
                "Lock file 'version' must be an integer: {$this->path}"
            );
        }
        if (! is_string($data['locked_at'])) {
            throw new CorruptLockFileException(
                "Lock file 'locked_at' must be a string: {$this->path}"
            );
        }
        if (! is_string($data['fingerprint'])) {
            throw new CorruptLockFileException(
                "Lock file 'fingerprint' must be a string: {$this->path}"
            );
        }
        if (! is_array($data['config'])) {
            throw new CorruptLockFileException(
                "Lock file 'config' must be an object: {$this->path}"
            );
        }

        return new LockFileContents(
            $data['version'],
            $data['locked_at'],
            $data['fingerprint'],
            $data['config'],
        );
    }

    public function write(PublicIdConfig $config, string $fingerprint): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new LockFileWriteException(
                    "Unable to create lock file directory: {$directory}"
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
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $e) {
            throw new LockFileWriteException(
                "Failed to encode lock file payload: {$this->path}",
                0,
                $e
            );
        }

        $bytes = @file_put_contents($this->path, $json.PHP_EOL);
        if ($bytes === false) {
            throw new LockFileWriteException(
                "Failed to write lock file: {$this->path}"
            );
        }
    }

    public function delete(): void
    {
        if (! $this->exists()) {
            return;
        }
        if (! @unlink($this->path)) {
            throw new LockFileWriteException(
                "Failed to delete lock file: {$this->path}"
            );
        }
    }

    private function nowIso8601Utc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
