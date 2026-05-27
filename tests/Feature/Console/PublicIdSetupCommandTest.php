<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Console;

use Progravity\Auth\PublicId\Config\ConfigFingerprint;
use Progravity\Auth\PublicId\Config\ConfigGuard;
use Progravity\Auth\PublicId\Config\LockFile;
use Progravity\Auth\PublicId\Config\PublicIdConfig;
use Progravity\Auth\Tests\TestCase;

class PublicIdSetupCommandTest extends TestCase
{
    private string $tmpDir;

    private string $tmpLockPath;

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    public function test_writes_lock_file_when_user_confirms(): void
    {
        $this->artisan('progravity:public-id:setup')
            ->expectsConfirmation('Lock this configuration?', 'yes')
            ->expectsOutputToContain('Public ID configuration locked')
            ->assertSuccessful();

        $this->assertFileExists($this->tmpLockPath);
    }

    public function test_does_not_write_when_user_declines(): void
    {
        $this->artisan('progravity:public-id:setup')
            ->expectsConfirmation('Lock this configuration?', 'no')
            ->expectsOutputToContain('Setup canceled')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->tmpLockPath);
    }

    public function test_displays_configuration_and_sample_ids(): void
    {
        $this->artisan('progravity:public-id:setup')
            ->expectsOutputToContain('Body length')
            ->expectsOutputToContain('lowercase_alphanumeric')
            ->expectsOutputToContain('Sample IDs')
            ->expectsOutputToContain('usr_')
            ->expectsOutputToContain('Collision probability')
            ->expectsConfirmation('Lock this configuration?', 'no')
            ->assertSuccessful();
    }

    public function test_refuses_when_already_locked_with_matching_fingerprint(): void
    {
        $config = $this->app->make(PublicIdConfig::class);
        $fingerprint = $this->app->make(ConfigFingerprint::class)->compute($config);
        $this->app->make(LockFile::class)->write($config, $fingerprint);
        $this->rebindGuard();

        $this->artisan('progravity:public-id:setup')
            ->expectsOutputToContain('already locked')
            ->assertSuccessful();
    }

    public function test_refuses_when_drifted_and_shows_diff(): void
    {
        $config = $this->app->make(PublicIdConfig::class);
        $bogusFingerprint = 'sha256:'.str_repeat('0', 64);
        $this->app->make(LockFile::class)->write($config, $bogusFingerprint);
        $this->rebindGuard();

        $this->artisan('progravity:public-id:setup')
            ->expectsOutputToContain('does not match')
            ->expectsOutputToContain('drifted')
            ->assertFailed();
    }

    protected function defineEnvironment($app): void
    {
        $this->tmpDir = sys_get_temp_dir().'/progravity-setup-cmd-'.uniqid('', true);
        $this->tmpLockPath = $this->tmpDir.'/auth.lock.json';
        $app['config']->set('progravity.auth.public_id.lock_file_path', $this->tmpLockPath);
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

    private function rebindGuard(): void
    {
        $this->app->forgetInstance(ConfigGuard::class);
    }
}
