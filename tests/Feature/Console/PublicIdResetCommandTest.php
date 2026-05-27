<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Console;

use Progravity\Auth\PublicId\Config\ConfigFingerprint;
use Progravity\Auth\PublicId\Config\LockFile;
use Progravity\Auth\PublicId\Config\PublicIdConfig;
use Progravity\Auth\Tests\TestCase;

class PublicIdResetCommandTest extends TestCase
{
    private string $tmpDir;

    private string $tmpLockPath;

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    public function test_refuses_without_awkward_flag(): void
    {
        $this->artisan('progravity:public-id:reset')
            ->expectsOutputToContain('discards the public_id configuration lock')
            ->expectsOutputToContain('--i-understand-this-breaks-existing-ids')
            ->assertFailed();
    }

    public function test_refuses_in_production_without_force_flag(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('progravity:public-id:reset --i-understand-this-breaks-existing-ids')
            ->expectsOutputToContain('refuses to run in production')
            ->expectsOutputToContain('--force-production')
            ->assertFailed();
    }

    public function test_proceeds_in_production_with_force_flag(): void
    {
        $this->writeMatchingLockFile();
        $this->app['env'] = 'production';

        $this->artisan('progravity:public-id:reset --i-understand-this-breaks-existing-ids --force-production')
            ->expectsConfirmation('Reset the public_id lock?', 'yes')
            ->expectsOutputToContain('Lock file removed')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->tmpLockPath);
    }

    public function test_does_not_delete_when_user_declines(): void
    {
        $this->writeMatchingLockFile();

        $this->artisan('progravity:public-id:reset --i-understand-this-breaks-existing-ids')
            ->expectsConfirmation('Reset the public_id lock?', 'no')
            ->expectsOutputToContain('Reset canceled')
            ->assertSuccessful();

        $this->assertFileExists($this->tmpLockPath);
    }

    public function test_deletes_lock_file_when_user_confirms(): void
    {
        $this->writeMatchingLockFile();

        $this->artisan('progravity:public-id:reset --i-understand-this-breaks-existing-ids')
            ->expectsConfirmation('Reset the public_id lock?', 'yes')
            ->expectsOutputToContain('Lock file removed')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->tmpLockPath);
    }

    public function test_no_lock_file_to_reset_returns_success(): void
    {
        $this->artisan('progravity:public-id:reset --i-understand-this-breaks-existing-ids')
            ->expectsConfirmation('Reset the public_id lock?', 'yes')
            ->expectsOutputToContain('No lock file to reset')
            ->assertSuccessful();
    }

    protected function defineEnvironment($app): void
    {
        $this->tmpDir = sys_get_temp_dir().'/progravity-reset-cmd-'.uniqid('', true);
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

    private function writeMatchingLockFile(): void
    {
        $config = $this->app->make(PublicIdConfig::class);
        $fingerprint = $this->app->make(ConfigFingerprint::class)->compute($config);
        $this->app->make(LockFile::class)->write($config, $fingerprint);
    }
}
