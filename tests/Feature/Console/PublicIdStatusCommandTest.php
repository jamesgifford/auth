<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\ConfigGuard;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModel;
use JamesGifford\Auth\Tests\TestCase;

class PublicIdStatusCommandTest extends TestCase
{
    private string $tmpDir;

    private string $tmpLockPath;

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    public function test_status_when_no_lock_file_shows_not_locked(): void
    {
        $this->artisan('jamesgifford:public-id:status')
            ->expectsOutputToContain('NOT LOCKED')
            ->assertSuccessful();
    }

    public function test_status_when_locked_shows_fingerprint_and_timestamp(): void
    {
        $config = $this->app->make(PublicIdConfig::class);
        $fingerprint = $this->app->make(ConfigFingerprint::class)->compute($config);
        $this->app->make(LockFile::class)->write($config, $fingerprint);
        $this->rebindGuard();

        $this->artisan('jamesgifford:public-id:status')
            ->expectsOutputToContain('Status: LOCKED')
            ->expectsOutputToContain($fingerprint)
            ->expectsOutputToContain('Locked at:')
            ->assertSuccessful();
    }

    public function test_status_when_drifted_shows_drifted_and_diff(): void
    {
        $config = $this->app->make(PublicIdConfig::class);
        $this->app->make(LockFile::class)->write($config, 'sha256:'.str_repeat('0', 64));
        $this->rebindGuard();

        $this->artisan('jamesgifford:public-id:status')
            ->expectsOutputToContain('DRIFTED')
            ->expectsOutputToContain('Changed fields')
            ->assertSuccessful();
    }

    public function test_status_always_displays_current_configuration(): void
    {
        $this->artisan('jamesgifford:public-id:status')
            ->expectsOutputToContain('Current configuration')
            ->expectsOutputToContain('Body length')
            ->expectsOutputToContain('Total max length')
            ->assertSuccessful();
    }

    public function test_status_shows_none_when_no_prefixes_registered(): void
    {
        // Clear the package's shipped default prefixes (which boot eagerly
        // registers, e.g. Account) so nothing is registered for this scenario.
        config(['jamesgifford.auth.public_id.prefixes' => []]);
        $this->app->forgetInstance(PublicIdConfig::class);
        $this->app->forgetInstance(PrefixRegistry::class);

        $this->artisan('jamesgifford:public-id:status')
            ->expectsOutputToContain('Registered prefixes: (none)')
            ->assertSuccessful();
    }

    public function test_status_lists_registered_prefixes(): void
    {
        Model::clearBootedModels();
        $this->app->make(PrefixRegistry::class)->register(FixtureModel::class);

        $this->artisan('jamesgifford:public-id:status')
            ->expectsOutputToContain(FixtureModel::class)
            ->expectsOutputToContain('fix')
            ->assertSuccessful();
    }

    protected function defineEnvironment($app): void
    {
        $this->tmpDir = sys_get_temp_dir().'/jamesgifford-status-cmd-'.uniqid('', true);
        $this->tmpLockPath = $this->tmpDir.'/auth.lock.json';
        $app['config']->set('jamesgifford.auth.public_id.lock_file_path', $this->tmpLockPath);
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
