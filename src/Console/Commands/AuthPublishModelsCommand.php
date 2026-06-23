<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Auth\Installer\ModelPublisher;

/**
 * Publish the package's models into the app as editable subclasses
 * (App\Models\Account, App\Models\AccountUser, App\Models\AccountRole) that
 * extend the package base models.
 *
 * Idempotent: existing target files are skipped (never overwritten), so a
 * consumer's customizations are preserved. The model-resolution config is not
 * rewritten automatically (preserving the consumer's config formatting/comments
 * is fragile); the exact config changes are printed instead.
 */
final class AuthPublishModelsCommand extends Command
{
    protected $signature = 'jamesgifford:auth:publish-models';

    protected $description = 'Publish editable App\\Models subclasses (Account, AccountUser, AccountRole) that extend the package base models.';

    public function __construct(private readonly ModelPublisher $publisher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Publishing model subclasses to '.$this->displayPath($this->publisher->modelDirectory()).'...');
        $this->newLine();

        $created = 0;
        $skipped = 0;

        foreach ($this->publisher->publish() as $result) {
            if ($result['status'] === 'created') {
                $created++;
                $this->line(sprintf(
                    '  ✓ created %s (%s extends %s)',
                    $this->displayPath($result['path']),
                    $result['appClass'],
                    $result['baseClass'],
                ));
            } else {
                $skipped++;
                $this->line('  ⊘ skipped '.$this->displayPath($result['path']).' (already exists; left untouched)');
            }
        }

        $this->newLine();
        $this->line(sprintf('Created %d, skipped %d.', $created, $skipped));

        $this->newLine();
        foreach ($this->publisher->configInstructions() as $line) {
            $this->line($line === '' ? '' : '  '.$line);
        }
        $this->newLine();
        $this->line('  The account model is used throughout the package; account_user and');
        $this->line('  account_role are provided primarily for your own customization.');

        return self::SUCCESS;
    }

    private function displayPath(string $path): string
    {
        $base = $this->laravel->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
