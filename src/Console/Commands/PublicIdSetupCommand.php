<?php

declare(strict_types=1);

namespace Progravity\Auth\Console\Commands;

use Illuminate\Console\Command;
use Progravity\Auth\PublicId\AlphabetRegistry;
use Progravity\Auth\PublicId\Config\ConfigFingerprint;
use Progravity\Auth\PublicId\Config\ConfigGuard;
use Progravity\Auth\PublicId\Config\GuardStatus;
use Progravity\Auth\PublicId\Config\LockFile;
use Progravity\Auth\PublicId\Config\PublicIdConfig;
use Progravity\Auth\PublicId\Exceptions\LockFileWriteException;
use Progravity\Auth\PublicId\Exceptions\PublicIdConfigLockedException;
use Progravity\Auth\PublicId\Generator;

final class PublicIdSetupCommand extends Command
{
    protected $signature = 'progravity:public-id:setup';

    protected $description = 'Lock the public_id configuration for this application.';

    public function __construct(
        private readonly PublicIdConfig $config,
        private readonly LockFile $lockFile,
        private readonly ConfigFingerprint $fingerprintCalculator,
        private readonly ConfigGuard $guard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $status = $this->guard->status();

        if ($status === GuardStatus::Locked) {
            $this->info('Public ID configuration is already locked.');
            $this->line('  Locked fingerprint: '.$this->guard->lockedFingerprint());
            try {
                $contents = $this->lockFile->read();
                $this->line('  Locked at:          '.$contents->lockedAt);
            } catch (\Throwable) {
                // best-effort: skip the timestamp line if read fails
            }
            $this->newLine();
            $this->line('If you intended to change the configuration, use `progravity:public-id:reset` first (destructive).');

            return self::SUCCESS;
        }

        if ($status === GuardStatus::Drifted) {
            $this->warn('Lock file exists but does not match current configuration.');
            $this->newLine();
            try {
                $this->guard->assertMatches();
            } catch (PublicIdConfigLockedException $e) {
                $this->line($e->getMessage());
            }
            $this->newLine();
            $this->line('Either revert your config changes to match the lock, or run `progravity:public-id:reset` to discard the existing lock.');

            return self::FAILURE;
        }

        $this->displayConfiguration();
        $this->newLine();
        $this->displaySampleIds();
        $this->newLine();
        $this->displayCollisionMath();
        $this->newLine();

        if (! $this->confirm('Lock this configuration?', false)) {
            $this->line('Setup canceled.');

            return self::SUCCESS;
        }

        try {
            $this->lockFile->write(
                $this->config,
                $this->fingerprintCalculator->compute($this->config),
            );
        } catch (LockFileWriteException $e) {
            $this->error('Failed to write lock file at '.$this->lockFile->path().': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Public ID configuration locked.');
        $this->newLine();
        $this->line('Lock file written to: '.$this->lockFile->path());
        $this->newLine();
        $this->displayNextSteps();

        return self::SUCCESS;
    }

    private function displayConfiguration(): void
    {
        $this->info('The following public ID configuration will be locked:');
        $this->newLine();

        $alphabet = $this->config->bodyAlphabet();
        $alphabetValue = $this->config->bodyAlphabetConfigValue();
        $registry = app(AlphabetRegistry::class);

        $alphabetDisplay = $registry->has($alphabetValue)
            ? sprintf('%s (%d chars: %s)', $alphabetValue, $alphabet->size(), $alphabet->toString())
            : sprintf('(raw, %d chars: %s)', $alphabet->size(), $alphabet->toString());

        $this->line(sprintf('  Body length:        %d', $this->config->bodyLength()));
        $this->line('  Body alphabet:      '.$alphabetDisplay);
        $this->line('  Checksum enabled:   '.($this->config->checksumEnabled() ? 'yes' : 'no'));
        $this->line(sprintf('  Checksum length:    %d', $this->config->checksumLength()));
        $this->line('  Checksum strategy:  '.$this->config->checksumStrategy());
        $this->line('  Separator:          '.$this->config->separator());
        $this->line(sprintf('  Prefix max length:  %d  (not part of locked fingerprint)', $this->config->prefixMaxLength()));
        $this->newLine();
        $this->line(sprintf('Total maximum ID length: %d characters', $this->config->totalMaxLength()));
    }

    private function displaySampleIds(): void
    {
        $this->info('Sample IDs that will be generated:');
        $this->newLine();

        $generator = app(Generator::class);
        foreach ($this->samplePrefixes() as $prefix) {
            $this->line('  '.$generator->generate($prefix));
        }
    }

    /**
     * @return array<int, string>
     */
    private function samplePrefixes(): array
    {
        $max = $this->config->prefixMaxLength();
        if ($max >= 3) {
            return ['usr', 'acc', 'inv'];
        }
        if ($max === 2) {
            return ['us', 'ac', 'in'];
        }

        return ['a', 'b', 'c'];
    }

    private function displayCollisionMath(): void
    {
        $size = $this->config->bodyAlphabet()->size();
        $length = $this->config->bodyLength();

        $logN = $length * log10($size);
        $log50 = 0.5 * $logN + 0.5 * log10(M_PI / 2);

        $this->info('Collision probability:');
        $this->newLine();
        $this->line(sprintf('  Possible IDs:                ~10^%d', (int) round($logN)));
        $this->line(sprintf('  50%% collision threshold:     ~10^%d records', (int) round($log50)));

        foreach ([
            ['1M', 6.0],
            ['100M', 8.0],
            ['1B', 9.0],
        ] as [$label, $logK]) {
            $logP = 2 * $logK - log10(2.0) - $logN;
            $exponent = (int) round($logP);
            $rendered = $logP < -300
                ? '<10^-300 (effectively zero)'
                : sprintf('~10^%d', $exponent);
            $this->line(sprintf('  Probability at %-5s records: %s', $label, $rendered));
        }
    }

    private function displayNextSteps(): void
    {
        $this->info('Next steps:');
        $this->line('  1. Commit '.$this->lockFile->path().' to your repository.');
        $this->line('  2. Use Progravity\\Auth\\PublicId\\PublicId::maxLength() in migrations:');
        $this->line('       $table->string(\'public_id\', PublicId::maxLength())->unique();');
        $this->line('  3. Apply the HasPublicId trait to models that need public IDs.');
        $this->line('  4. Either implement publicIdPrefix() on each model, or register');
        $this->line('     prefixes in config/progravity/auth.php under public_id.prefixes.');
        $this->newLine();
        $this->line('Run `php artisan progravity:public-id:status` to verify the lock at any time.');
    }
}
