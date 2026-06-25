<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\PublicId\Config;

use JamesGifford\Auth\PublicId\AlphabetRegistry;
use JamesGifford\Auth\PublicId\Checksum\NullChecksum;
use JamesGifford\Auth\PublicId\Checksum\PositionalSumChecksum;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\ConfigGuard;
use JamesGifford\Auth\PublicId\Config\GuardStatus;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Exceptions\PublicIdConfigLockedException;
use JamesGifford\Auth\Tests\TestCase;

class ConfigGuardTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/jamesgifford-auth-guard-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    public function test_status_not_yet_locked_when_no_file(): void
    {
        $guard = $this->makeGuard($this->makeConfig());

        $this->assertSame(GuardStatus::NotYetLocked, $guard->status());
    }

    public function test_status_locked_when_fingerprint_matches(): void
    {
        $config = $this->makeConfig();
        $lockPath = $this->tmpDir.'/auth.lock.json';
        $fingerprintCalc = new ConfigFingerprint;
        (new LockFile($lockPath))->write($config, $fingerprintCalc->compute($config));

        $guard = $this->makeGuard($config, $lockPath);

        $this->assertSame(GuardStatus::Locked, $guard->status());
    }

    public function test_status_drifted_when_fingerprint_differs(): void
    {
        $original = $this->makeConfig();
        $lockPath = $this->tmpDir.'/auth.lock.json';
        $fingerprintCalc = new ConfigFingerprint;
        (new LockFile($lockPath))->write($original, $fingerprintCalc->compute($original));

        $changed = $this->makeConfig(['body.length' => 16]);
        $guard = $this->makeGuard($changed, $lockPath);

        $this->assertSame(GuardStatus::Drifted, $guard->status());
    }

    public function test_assert_matches_does_nothing_when_not_yet_locked(): void
    {
        $guard = $this->makeGuard($this->makeConfig());

        $guard->assertMatches();

        $this->assertTrue(true); // reaching here means no throw
    }

    public function test_assert_matches_does_nothing_when_locked(): void
    {
        $config = $this->makeConfig();
        $lockPath = $this->tmpDir.'/auth.lock.json';
        $fingerprintCalc = new ConfigFingerprint;
        (new LockFile($lockPath))->write($config, $fingerprintCalc->compute($config));

        $guard = $this->makeGuard($config, $lockPath);

        $guard->assertMatches();

        $this->assertTrue(true);
    }

    public function test_assert_matches_throws_when_drifted(): void
    {
        $original = $this->makeConfig();
        $lockPath = $this->tmpDir.'/auth.lock.json';
        $fingerprintCalc = new ConfigFingerprint;
        (new LockFile($lockPath))->write($original, $fingerprintCalc->compute($original));

        $changed = $this->makeConfig(['body.length' => 16]);
        $guard = $this->makeGuard($changed, $lockPath);

        $this->expectException(PublicIdConfigLockedException::class);
        $guard->assertMatches();
    }

    public function test_drift_exception_message_contains_both_fingerprints_and_diff(): void
    {
        $original = $this->makeConfig();
        $lockPath = $this->tmpDir.'/auth.lock.json';
        $fingerprintCalc = new ConfigFingerprint;
        $lockedFingerprint = $fingerprintCalc->compute($original);
        (new LockFile($lockPath))->write($original, $lockedFingerprint);

        $changed = $this->makeConfig([
            'body.length' => 16,
            'checksum.enabled' => false,
            'checksum.length' => 0,
            'checksum.strategy' => NullChecksum::class,
        ]);
        $currentFingerprint = $fingerprintCalc->compute($changed);

        $guard = $this->makeGuard($changed, $lockPath);

        try {
            $guard->assertMatches();
            $this->fail('Expected PublicIdConfigLockedException');
        } catch (PublicIdConfigLockedException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString($lockedFingerprint, $message);
            $this->assertStringContainsString($currentFingerprint, $message);
            $this->assertStringContainsString('body.length: 18 → 16', $message);
            $this->assertStringContainsString('checksum.enabled: true → false', $message);
            $this->assertSame($lockedFingerprint, $e->lockedFingerprint);
            $this->assertSame($currentFingerprint, $e->currentFingerprint);
        }
    }

    public function test_locked_fingerprint_returns_null_when_not_yet_locked(): void
    {
        $guard = $this->makeGuard($this->makeConfig());

        $this->assertNull($guard->lockedFingerprint());
    }

    public function test_locked_fingerprint_returns_stored_value(): void
    {
        $config = $this->makeConfig();
        $lockPath = $this->tmpDir.'/auth.lock.json';
        $fingerprintCalc = new ConfigFingerprint;
        $expected = $fingerprintCalc->compute($config);
        (new LockFile($lockPath))->write($config, $expected);

        $guard = $this->makeGuard($config, $lockPath);

        $this->assertSame($expected, $guard->lockedFingerprint());
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
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

    private function makeConfig(array $overrides = []): PublicIdConfig
    {
        $base = $this->baseConfig();
        foreach ($overrides as $path => $value) {
            $segments = explode('.', $path);
            $cursor = &$base;
            foreach ($segments as $i => $segment) {
                if ($i === count($segments) - 1) {
                    $cursor[$segment] = $value;
                } else {
                    $cursor = &$cursor[$segment];
                }
            }
            unset($cursor);
        }

        return new PublicIdConfig($base, new AlphabetRegistry);
    }

    private function makeGuard(PublicIdConfig $config, ?string $lockPath = null): ConfigGuard
    {
        $lockPath ??= $this->tmpDir.'/auth.lock.json';

        return new ConfigGuard($config, new LockFile($lockPath), new ConfigFingerprint);
    }
}
