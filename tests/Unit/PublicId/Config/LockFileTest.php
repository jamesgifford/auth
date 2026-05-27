<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Unit\PublicId\Config;

use Progravity\Auth\PublicId\AlphabetRegistry;
use Progravity\Auth\PublicId\Checksum\PositionalSumChecksum;
use Progravity\Auth\PublicId\Config\LockFile;
use Progravity\Auth\PublicId\Config\LockFileContents;
use Progravity\Auth\PublicId\Config\PublicIdConfig;
use Progravity\Auth\PublicId\Exceptions\IncompleteLockFileException;
use Progravity\Auth\PublicId\Exceptions\MalformedLockFileException;
use Progravity\Auth\PublicId\Exceptions\MissingLockFileException;
use Progravity\Auth\Tests\TestCase;

class LockFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/progravity-auth-lockfile-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    public function test_exists_returns_false_when_file_does_not_exist(): void
    {
        $lockFile = new LockFile($this->tmpDir.'/missing.lock.json');

        $this->assertFalse($lockFile->exists());
    }

    public function test_read_throws_when_file_does_not_exist(): void
    {
        $lockFile = new LockFile($this->tmpDir.'/missing.lock.json');

        $this->expectException(MissingLockFileException::class);
        $lockFile->read();
    }

    public function test_write_creates_file_with_expected_structure(): void
    {
        $path = $this->tmpDir.'/auth.lock.json';
        $lockFile = new LockFile($path);
        $config = $this->makeConfig();

        $lockFile->write($config, 'sha256:abcdef');

        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $decoded = json_decode($raw, true);

        $this->assertSame(1, $decoded['version']);
        $this->assertSame('sha256:abcdef', $decoded['fingerprint']);
        $this->assertSame('_', $decoded['config']['separator']);
        $this->assertSame(18, $decoded['config']['body']['length']);
        $this->assertSame('lowercase_alphanumeric', $decoded['config']['body']['alphabet']);
        $this->assertTrue($decoded['config']['checksum']['enabled']);
        $this->assertSame(2, $decoded['config']['checksum']['length']);
        $this->assertSame(PositionalSumChecksum::class, $decoded['config']['checksum']['strategy']);
    }

    public function test_write_creates_parent_directories_if_missing(): void
    {
        $path = $this->tmpDir.'/nested/deeper/auth.lock.json';
        $lockFile = new LockFile($path);

        $lockFile->write($this->makeConfig(), 'sha256:xyz');

        $this->assertFileExists($path);
    }

    public function test_read_after_write_returns_matching_contents(): void
    {
        $path = $this->tmpDir.'/auth.lock.json';
        $lockFile = new LockFile($path);

        $lockFile->write($this->makeConfig(), 'sha256:fingerprint-value');
        $contents = $lockFile->read();

        $this->assertInstanceOf(LockFileContents::class, $contents);
        $this->assertSame(1, $contents->version);
        $this->assertSame('sha256:fingerprint-value', $contents->fingerprint);
        $this->assertIsArray($contents->config);
        $this->assertSame('_', $contents->config['separator']);
        $this->assertSame(18, $contents->config['body']['length']);
    }

    public function test_locked_at_is_iso_8601_utc_with_z_suffix(): void
    {
        $path = $this->tmpDir.'/auth.lock.json';
        $lockFile = new LockFile($path);

        $lockFile->write($this->makeConfig(), 'sha256:zzz');
        $contents = $lockFile->read();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $contents->lockedAt
        );
    }

    public function test_read_throws_on_malformed_json(): void
    {
        $path = $this->tmpDir.'/auth.lock.json';
        mkdir($this->tmpDir, 0755, true);
        file_put_contents($path, '{not valid json');

        $lockFile = new LockFile($path);

        $this->expectException(MalformedLockFileException::class);
        $lockFile->read();
    }

    public function test_read_throws_when_required_key_missing(): void
    {
        $path = $this->tmpDir.'/auth.lock.json';
        mkdir($this->tmpDir, 0755, true);
        file_put_contents($path, json_encode([
            'version' => 1,
            'locked_at' => '2026-05-06T00:00:00Z',
            // 'fingerprint' missing
            'config' => [],
        ]));

        $lockFile = new LockFile($path);

        $this->expectException(IncompleteLockFileException::class);
        $this->expectExceptionMessage('fingerprint');
        $lockFile->read();
    }

    public function test_incomplete_lock_file_lists_all_missing_keys(): void
    {
        $path = $this->tmpDir.'/auth.lock.json';
        mkdir($this->tmpDir, 0755, true);
        file_put_contents($path, json_encode([
            'version' => 1,
            // 'locked_at', 'fingerprint', 'config' all missing
        ]));

        $lockFile = new LockFile($path);

        try {
            $lockFile->read();
            $this->fail('Expected IncompleteLockFileException');
        } catch (IncompleteLockFileException $e) {
            $this->assertStringContainsString('locked_at', $e->getMessage());
            $this->assertStringContainsString('fingerprint', $e->getMessage());
            $this->assertStringContainsString('config', $e->getMessage());
        }
    }

    public function test_delete_removes_existing_file(): void
    {
        $path = $this->tmpDir.'/auth.lock.json';
        $lockFile = new LockFile($path);

        $lockFile->write($this->makeConfig(), 'sha256:zzz');
        $this->assertFileExists($path);

        $lockFile->delete();

        $this->assertFileDoesNotExist($path);
    }

    public function test_delete_noop_when_file_does_not_exist(): void
    {
        $lockFile = new LockFile($this->tmpDir.'/missing.lock.json');

        $lockFile->delete();

        $this->assertFalse($lockFile->exists());
    }

    public function test_path_returns_configured_path(): void
    {
        $lockFile = new LockFile('/tmp/some/path.json');

        $this->assertSame('/tmp/some/path.json', $lockFile->path());
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

    private function makeConfig(): PublicIdConfig
    {
        $array = [
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

        return new PublicIdConfig($array, new AlphabetRegistry);
    }
}
