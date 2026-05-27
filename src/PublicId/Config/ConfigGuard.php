<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Config;

use Progravity\Auth\PublicId\Exceptions\PublicIdConfigLockedException;

/**
 * Boot-time check that the current public_id config matches the locked
 * fingerprint on disk.
 *
 * The service provider calls {@see assertMatches()} during boot. If the
 * lock file exists but the fingerprint doesn't match, the boot throws —
 * preventing the app from running with a configuration that diverges
 * from the one IDs were generated under.
 *
 * Drift causes: any change to body length, body alphabet, separator,
 * or checksum settings after `progravity:public-id:setup` was run.
 */
final class ConfigGuard
{
    private ?LockFileContents $cachedContents = null;

    private bool $contentsLoaded = false;

    private ?string $cachedCurrentFingerprint = null;

    public function __construct(
        private readonly PublicIdConfig $config,
        private readonly LockFile $lockFile,
        private readonly ConfigFingerprint $fingerprintCalculator,
    ) {}

    /**
     * Classify the current state: NotYetLocked, Locked, or Drifted.
     */
    public function status(): GuardStatus
    {
        $contents = $this->loadContents();
        if ($contents === null) {
            return GuardStatus::NotYetLocked;
        }
        if ($contents->fingerprint === $this->currentFingerprint()) {
            return GuardStatus::Locked;
        }

        return GuardStatus::Drifted;
    }

    /**
     * No-op when not yet locked or when the fingerprint matches.
     *
     * @throws PublicIdConfigLockedException when the lock file exists but
     *                                       the current config no longer matches it
     */
    public function assertMatches(): void
    {
        $contents = $this->loadContents();
        if ($contents === null) {
            return;
        }

        $current = $this->currentFingerprint();
        if ($contents->fingerprint === $current) {
            return;
        }

        throw new PublicIdConfigLockedException(
            $contents->fingerprint,
            $current,
            $this->buildDiff($contents->config, $this->config->fingerprintFields()),
        );
    }

    /**
     * Stored fingerprint from the lock file, or null when no lock exists.
     */
    public function lockedFingerprint(): ?string
    {
        $contents = $this->loadContents();

        return $contents?->fingerprint;
    }

    /**
     * Fingerprint of the in-memory configuration (irrespective of any lock file).
     */
    public function currentFingerprint(): string
    {
        if ($this->cachedCurrentFingerprint === null) {
            $this->cachedCurrentFingerprint = $this->fingerprintCalculator->compute($this->config);
        }

        return $this->cachedCurrentFingerprint;
    }

    private function loadContents(): ?LockFileContents
    {
        if (! $this->contentsLoaded) {
            $this->cachedContents = $this->lockFile->exists()
                ? $this->lockFile->read()
                : null;
            $this->contentsLoaded = true;
        }

        return $this->cachedContents;
    }

    /**
     * @param  array<string, mixed>  $storedNested
     * @param  array<string, mixed>  $current
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function buildDiff(array $storedNested, array $current): array
    {
        $stored = $this->flatten($storedNested);

        $diff = [];
        $keys = array_unique(array_merge(array_keys($stored), array_keys($current)));
        foreach ($keys as $key) {
            $from = $stored[$key] ?? null;
            $to = $current[$key] ?? null;
            if ($from !== $to) {
                $diff[$key] = ['from' => $from, 'to' => $to];
            }
        }
        ksort($diff);

        return $diff;
    }

    /**
     * @param  array<string, mixed>  $nested
     * @return array<string, mixed>
     */
    private function flatten(array $nested, string $prefix = ''): array
    {
        $flat = [];
        foreach ($nested as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat = array_merge($flat, $this->flatten($value, $fullKey));
            } else {
                $flat[$fullKey] = $value;
            }
        }

        return $flat;
    }
}
