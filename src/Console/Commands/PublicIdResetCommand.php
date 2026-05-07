<?php

declare(strict_types=1);

namespace Progravity\Auth\Console\Commands;

use Illuminate\Console\Command;
use Progravity\Auth\PublicId\Config\LockFile;
use Progravity\Auth\PublicId\Exceptions\LockFileWriteException;

final class PublicIdResetCommand extends Command
{
    protected $signature = 'progravity:public-id:reset {--i-understand-this-breaks-existing-ids} {--force-production}';

    protected $description = 'Clear the public_id configuration lock. DESTRUCTIVE: invalidates all previously generated IDs.';

    public function __construct(private readonly LockFile $lockFile)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('i-understand-this-breaks-existing-ids')) {
            $this->warn('This command discards the public_id configuration lock.');
            $this->newLine();
            $this->line('This is destructive: any IDs generated under the previous configuration');
            $this->line('may not validate or generate consistently after a new lock is written.');
            $this->newLine();
            $this->line('To proceed, run with the explicit flag:');
            $this->newLine();
            $this->line('  php artisan progravity:public-id:reset --i-understand-this-breaks-existing-ids');
            $this->newLine();

            return self::FAILURE;
        }

        if ($this->getLaravel()->environment() === 'production' && ! $this->option('force-production')) {
            $this->warn('This command refuses to run in production by default.');
            $this->newLine();
            $this->line('If you are absolutely certain, run with --force-production:');
            $this->newLine();
            $this->line('  php artisan progravity:public-id:reset --i-understand-this-breaks-existing-ids --force-production');
            $this->newLine();

            return self::FAILURE;
        }

        if (! $this->confirm('Reset the public_id lock?', false)) {
            $this->line('Reset canceled.');

            return self::SUCCESS;
        }

        if (! $this->lockFile->exists()) {
            $this->info('No lock file to reset.');

            return self::SUCCESS;
        }

        try {
            $this->lockFile->delete();
        } catch (LockFileWriteException $e) {
            $this->error('Failed to delete lock file: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Lock file removed.');
        $this->newLine();
        $this->line('You can now run progravity:public-id:setup to re-lock the configuration.');
        $this->newLine();
        $this->line('If your repository tracks the lock file (recommended), the next commit');
        $this->line('should reflect this deletion. Verify the file has been removed:');
        $this->newLine();
        $this->line('  git status');
        $this->newLine();

        return self::SUCCESS;
    }
}
