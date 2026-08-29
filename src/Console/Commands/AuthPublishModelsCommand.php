<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Auth\Installer\ModelPublisher;

/**
 * Publish the package's models into the app as editable subclasses
 * (App\Models\Account, App\Models\AccountUser, App\Models\AccountRole) that
 * extend the package base models, and register each of them in the
 * model-resolution config so the package actually uses them.
 *
 * Idempotent: existing target files are skipped (never overwritten), so a
 * consumer's customizations are preserved. Re-running rewires an
 * already-correct config value to the same value.
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
        $this->ensureConfigPublished();
        $this->registerAndReport();

        $this->newLine();
        $this->line('  The account model is used throughout the package; account_user and');
        $this->line('  account_role are provided primarily for your own customization.');

        return self::SUCCESS;
    }

    /**
     * Publish the package config if the consumer hasn't already, so
     * registerAndReport() has a file to edit. Never overwrites an existing
     * file (the consumer may have edited it). Uses callSilent (not the
     * Artisan facade) so the nested command's output doesn't clobber this
     * command's own Artisan::output() buffer.
     */
    private function ensureConfigPublished(): void
    {
        if (is_file($this->publisher->publishedConfigPath())) {
            return;
        }

        $this->callSilent('vendor:publish', ['--tag' => 'jamesgifford-auth-config']);
    }

    /**
     * Wire every genuinely-published subclass into the models config,
     * report what changed, and flag anything that still doesn't match.
     */
    private function registerAndReport(): void
    {
        $configPath = $this->displayPath($this->publisher->publishedConfigPath());
        $outcome = $this->publisher->registerPublishedModels();

        if ($outcome['registered'] !== []) {
            $this->line("Registered in {$configPath}:");
            $this->newLine();
            $this->line("  'models' => [");
            foreach ($outcome['registered'] as $key => $class) {
                $this->line(sprintf("      '%s' => \\%s::class,", $key, $class));
            }
            $this->line('  ],');
        }

        if ($outcome['failed'] !== []) {
            $this->newLine();
            $this->warn("Could not automatically update {$configPath}. Add these by hand:");
            $this->newLine();
            $this->line("  'models' => [");
            foreach ($this->publisher->configMap() as $key => $class) {
                if (in_array($key, $outcome['failed'], true)) {
                    $this->line(sprintf("      '%s' => \\%s::class,", $key, $class));
                }
            }
            $this->line('  ],');
        }

        $mismatches = array_filter(
            $this->publisher->configConsistency(),
            static fn (array $row): bool => $row['status'] !== 'registered',
        );

        if ($mismatches !== []) {
            $this->newLine();
            $this->error('Model-map mismatch detected:');
            foreach ($mismatches as $row) {
                $this->line($row['status'] === 'not_published'
                    ? "  ✗ models.{$row['configKey']} — no genuinely published subclass found"
                    : "  ✗ models.{$row['configKey']} does not resolve to \\{$row['appClass']}::class");
            }
        }
    }

    private function displayPath(string $path): string
    {
        $base = $this->laravel->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
