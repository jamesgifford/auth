<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Auth\PublicId\Config\ConfigGuard;
use JamesGifford\Auth\PublicId\Config\GuardStatus;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Exceptions\PublicIdConfigLockedException;
use JamesGifford\Auth\PublicId\PrefixRegistry;

/**
 * Read-only diagnostic showing the current public_id configuration state:
 * whether a lock file exists, whether it matches current config, the
 * fingerprint, the resolved configuration, and the registered prefixes.
 *
 * Returns success in all states; this command never enforces.
 *
 * Run with: `php artisan jamesgifford:public-id:status`
 */
final class PublicIdStatusCommand extends Command
{
    protected $signature = 'jamesgifford:public-id:status';

    protected $description = 'Display the current public_id configuration status.';

    public function __construct(
        private readonly PublicIdConfig $config,
        private readonly LockFile $lockFile,
        private readonly ConfigGuard $guard,
        private readonly PrefixRegistry $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Public ID Configuration Status');
        $this->newLine();

        $status = $this->guard->status();

        match ($status) {
            GuardStatus::NotYetLocked => $this->warn('Status: NOT LOCKED — run jamesgifford:public-id:setup to lock.'),
            GuardStatus::Locked => $this->info('Status: LOCKED'),
            GuardStatus::Drifted => $this->error('Status: DRIFTED — config does not match lock file.'),
        };

        $this->newLine();

        if ($status !== GuardStatus::NotYetLocked) {
            $contents = $this->lockFile->read();
            $this->line('  Lock file:    '.$this->lockFile->path());
            $this->line('  Locked at:    '.$contents->lockedAt);
            $this->line('  Fingerprint:  '.$contents->fingerprint);
            $this->newLine();

            if ($status === GuardStatus::Drifted) {
                try {
                    $this->guard->assertMatches();
                } catch (PublicIdConfigLockedException $e) {
                    $this->line($e->getMessage());
                }
                $this->newLine();
            }
        }

        $this->displayCurrentConfiguration();
        $this->newLine();
        $this->displayRegisteredPrefixes();

        return self::SUCCESS;
    }

    private function displayCurrentConfiguration(): void
    {
        $this->info('Current configuration:');
        $this->newLine();
        $this->line(sprintf('  Body length:        %d', $this->config->bodyLength()));
        $this->line(sprintf(
            '  Body alphabet:      %s (%d chars)',
            $this->config->bodyAlphabetConfigValue(),
            $this->config->bodyAlphabet()->size(),
        ));
        $this->line('  Checksum enabled:   '.($this->config->checksumEnabled() ? 'yes' : 'no'));
        $this->line(sprintf('  Checksum length:    %d', $this->config->checksumLength()));
        $this->line('  Checksum strategy:  '.$this->config->checksumStrategy());
        $this->line('  Separator:          '.$this->config->separator());
        $this->line(sprintf('  Prefix max length:  %d', $this->config->prefixMaxLength()));
        $this->line(sprintf('  Total max length:   %d', $this->config->totalMaxLength()));
    }

    private function displayRegisteredPrefixes(): void
    {
        $registered = $this->registry->all();

        if ($registered === []) {
            $this->info('Registered prefixes: (none)');

            return;
        }

        ksort($registered);

        $this->info('Registered prefixes:');
        $this->newLine();

        $maxClassLen = max(array_map('strlen', array_keys($registered)));
        foreach ($registered as $modelClass => $prefix) {
            $this->line(sprintf('  %-'.$maxClassLen.'s  %s', $modelClass, $prefix));
        }
    }
}
