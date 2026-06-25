<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Installer\ModelPublisher;
use JamesGifford\Auth\Installer\PackageMigrations;
use JamesGifford\Auth\Installer\UserModelAnalysis;
use JamesGifford\Auth\Installer\UserModelModifier;
use JamesGifford\Auth\PublicId\Config\LockFile;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Counterpart to the install command: removes the package's setup from a
 * consuming application. Rolls back the package's migrations (dropping its
 * tables and the columns it added to users), deletes the published migration
 * files, and removes the public ID lock file.
 *
 * This is the path for removing the package when data exists — which the
 * installer's --fresh mode refuses to touch. Because it drops tables that may
 * hold real data, it carries the strongest safeguards in the package: a
 * production guard, an explicit data-loss summary, and a typed confirmation
 * (type "uninstall" to proceed).
 *
 * It does NOT edit the User model; automated reversion is deferred. It prints
 * tailored manual instructions for that one remaining step instead.
 */
final class AuthUninstallCommand extends Command
{
    protected $signature = 'jamesgifford:auth:uninstall
        {--keep-config : Keep the published config file (config/jamesgifford/auth.php) instead of deleting it}
        {--remove-published-models : Also delete the published App\Models subclasses (non-interactive opt-in; interactive runs prompt instead)}
        {--force-production : Permit uninstall to run in a production environment}
        {--force : Skip the interactive confirmation prompt (for non-interactive use)}';

    protected $description = 'Remove the JamesGifford Auth setup from this application. Destructive: drops tables and deletes data.';

    private bool $configDirRemoved = false;

    public function __construct(
        private readonly UserModelModifier $modifier,
        private readonly LockFile $lockFile,
        private readonly PackageMigrations $packageMigrations,
        private readonly ModelPublisher $modelPublisher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('JamesGifford Auth Uninstaller');
        $this->newLine();

        if ($this->laravel->environment() === 'production' && ! $this->option('force-production')) {
            $this->displayProductionRefusal();

            return self::FAILURE;
        }

        $summary = $this->gatherTeardownSummary();
        $this->displayWarning($summary);

        if (! $this->confirmTeardown()) {
            $this->newLine();
            $this->info('Uninstall canceled. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->runTeardown()) {
            return self::FAILURE;
        }

        $this->revertUserModel();
        $this->handlePublishedModels();
        $this->displayCompletion();

        return self::SUCCESS;
    }

    /**
     * Offer to remove the package's published App\Models subclasses. These
     * extend the package base models that uninstall just removed, so they will
     * be broken afterwards. Deleting consumer-app files is gated: interactive
     * runs PROMPT (default NO); non-interactive runs leave them and advise,
     * UNLESS --remove-published-models is passed.
     */
    private function handlePublishedModels(): void
    {
        $present = $this->detectPublishedModels();
        if ($present === []) {
            return;
        }

        $this->newLine();
        $this->line('These published model subclasses extend package base models that have now');
        $this->line('been removed, so they will be broken after uninstall:');
        foreach ($present as $model) {
            $this->line('  • '.$this->displayPath($model['path']));
        }
        $this->newLine();

        if (! $this->shouldRemovePublishedModels()) {
            $this->line('Left in place (they are your code). Delete or rewire them by hand, and undo');
            $this->line('the models config in config/jamesgifford/auth.php if you pointed it at them.');

            return;
        }

        foreach ($present as $model) {
            @unlink($model['path']);
            $this->line('  - removed '.$this->displayPath($model['path']));
        }
        $this->line('Remember to undo the models config in config/jamesgifford/auth.php if you');
        $this->line('wired it to these classes.');
    }

    /**
     * The package's published model files that actually exist AND are genuinely
     * the package's subclasses (they import the package base model) — so an
     * unrelated App\Models\Account is never mistaken for ours.
     *
     * @return list<array{name: string, path: string, baseClass: string}>
     */
    private function detectPublishedModels(): array
    {
        $present = [];
        foreach ($this->modelPublisher->candidatePaths() as $candidate) {
            if (! is_file($candidate['path'])) {
                continue;
            }
            $contents = (string) file_get_contents($candidate['path']);
            if (str_contains($contents, $candidate['baseClass'])) {
                $present[] = $candidate;
            }
        }

        return $present;
    }

    /**
     * Whether to delete the published models: the explicit flag forces yes; a
     * non-interactive run without it is a safe no; otherwise prompt (default no).
     */
    private function shouldRemovePublishedModels(): bool
    {
        if ($this->option('remove-published-models')) {
            return true;
        }

        if ($this->option('force') || ! $this->input->isInteractive()) {
            return false;
        }

        return $this->confirm('Delete these published model files? (they will be broken after uninstall)', false);
    }

    // ---- Step 1: production guard ----

    private function displayProductionRefusal(): void
    {
        $this->error('Refusing to run uninstall in a production environment.');
        $this->newLine();
        $this->line('If you are absolutely certain, re-run with --force-production. Back up');
        $this->line('your database first — dropped tables cannot be recovered.');
    }

    // ---- Step 2: data-loss summary ----

    /**
     * Compute exactly what teardown will remove, with real counts. A missing
     * table (partial install) is recorded as a note rather than erroring.
     *
     * @return array{
     *     accounts: ?int,
     *     memberships: ?int,
     *     customRoles: list<string>,
     *     usersAffected: ?int,
     *     userColumns: list<string>,
     *     migrationFileCount: int,
     *     lockFileExists: bool,
     *     lockPath: string,
     *     configFiles: list<string>,
     *     notes: list<string>,
     * }
     */
    private function gatherTeardownSummary(): array
    {
        $notes = [];

        // Counts include soft-deleted rows: force-rollback drops the whole
        // table regardless, and DB::table() ignores the soft-delete scope, so
        // these are effectively withTrashed() counts.
        $accounts = null;
        if (Schema::hasTable('accounts')) {
            $accounts = DB::table('accounts')->count();
        } else {
            $notes[] = 'accounts table not found — skipping';
        }

        $memberships = null;
        if (Schema::hasTable('account_user')) {
            $memberships = DB::table('account_user')->count();
        } else {
            $notes[] = 'account_user table not found — skipping';
        }

        $customRoles = [];
        if (Schema::hasTable('account_roles')) {
            /** @var list<string> $customRoles */
            $customRoles = DB::table('account_roles')
                ->where('system', false)
                ->orderBy('key')
                ->pluck('key')
                ->all();
        } else {
            $notes[] = 'account_roles table not found — skipping';
        }

        $userColumns = [];
        $hasPublicId = Schema::hasTable('users') && Schema::hasColumn('users', 'public_id');
        $hasCurrentAccount = Schema::hasTable('users') && Schema::hasColumn('users', 'current_account_id');
        if ($hasPublicId) {
            $userColumns[] = 'public_id';
        }
        if ($hasCurrentAccount) {
            $userColumns[] = 'current_account_id';
        }

        $usersAffected = null;
        if ($hasPublicId || $hasCurrentAccount) {
            $query = DB::table('users');
            $usersAffected = $query->where(function ($q) use ($hasPublicId, $hasCurrentAccount): void {
                if ($hasPublicId) {
                    $q->orWhereNotNull('public_id');
                }
                if ($hasCurrentAccount) {
                    $q->orWhereNotNull('current_account_id');
                }
            })->count();
        } elseif (Schema::hasTable('users')) {
            $notes[] = 'users table has no package columns — nothing to remove there';
        }

        return [
            'accounts' => $accounts,
            'memberships' => $memberships,
            'customRoles' => $customRoles,
            'usersAffected' => $usersAffected,
            'userColumns' => $userColumns,
            'migrationFileCount' => $this->packageMigrations->publishedFileCount(),
            'lockFileExists' => $this->lockFile->exists(),
            'lockPath' => $this->lockFile->path(),
            'configFiles' => array_values(array_filter($this->publishedConfigPaths(), 'is_file')),
            'notes' => $notes,
        ];
    }

    /**
     * Always-shown warning block. The wording carries the seriousness on its
     * own (so a no-ANSI render in CI or piped output is still unambiguous);
     * color only amplifies it — red for the headline, yellow for the counts
     * and the back-up caution.
     *
     * @param  array{accounts: ?int, memberships: ?int, customRoles: list<string>, usersAffected: ?int, userColumns: list<string>, migrationFileCount: int, lockFileExists: bool, lockPath: string, configFiles: list<string>, notes: list<string>}  $summary
     */
    private function displayWarning(array $summary): void
    {
        $this->line('<fg=red>WARNING: Uninstalling permanently deletes data and cannot be undone.</>');
        $this->newLine();

        // What uninstall does — always shown, no longer gated behind a flag.
        $this->line('Uninstall removes the auth package from this application. It will:');
        $this->newLine();
        $this->line('  • Roll back the package\'s migrations, dropping the accounts,');
        $this->line('    account_roles, and account_user tables');
        $this->line('  • Remove the public_id and current_account_id columns from users');
        $this->line('  • Delete the published migration files, the public ID lock file,');
        $this->line('    and the published config file');
        $this->newLine();

        $this->warn('This will permanently delete:');
        $this->newLine();

        if ($summary['accounts'] !== null) {
            $this->warn(sprintf('  • %d %s', $summary['accounts'], $this->pluralize($summary['accounts'], 'account', 'accounts')));
        }
        if ($summary['memberships'] !== null) {
            $this->warn(sprintf('  • %d %s', $summary['memberships'], $this->pluralize($summary['memberships'], 'membership', 'memberships')));
        }
        if ($summary['customRoles'] !== []) {
            $count = count($summary['customRoles']);
            $this->warn(sprintf(
                '  • %d %s (%s)',
                $count,
                $this->pluralize($count, 'custom role', 'custom roles'),
                implode(', ', $summary['customRoles']),
            ));
        }
        if ($summary['userColumns'] !== [] && $summary['usersAffected'] !== null) {
            $this->warn(sprintf(
                '  • %s %s from %d %s',
                implode(' and ', $summary['userColumns']),
                count($summary['userColumns']) === 1 ? 'column' : 'columns',
                $summary['usersAffected'],
                $this->pluralize($summary['usersAffected'], 'user', 'users'),
            ));
        }

        $this->newLine();
        $this->warn('And remove:');
        $this->newLine();
        $this->warn(sprintf(
            '  • %d published migration %s',
            $summary['migrationFileCount'],
            $this->pluralize($summary['migrationFileCount'], 'file', 'files'),
        ));
        if ($summary['lockFileExists']) {
            $this->warn('  • the public ID lock file ('.$this->displayPath($summary['lockPath']).')');
        } else {
            $this->warn('  • the public ID lock file (already absent)');
        }

        if ($this->option('keep-config')) {
            $this->line('  (the published config file will be kept — --keep-config)');
        } elseif ($summary['configFiles'] !== []) {
            foreach ($summary['configFiles'] as $configPath) {
                $this->warn('  • the published config file ('.$this->displayPath($configPath).')');
            }
        } else {
            $this->warn('  • the published config file (already absent)');
        }

        if ($summary['notes'] !== []) {
            $this->newLine();
            $this->line('Notes:');
            foreach ($summary['notes'] as $note) {
                $this->line('  • '.$note);
            }
        }

        $this->newLine();
        $this->warn('Back up your database first — dropped tables cannot be recovered.');
    }

    // ---- Step 3: confirmation ----

    private function confirmTeardown(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->newLine();
        $answer = $this->ask('Type "uninstall" to confirm, or anything else to cancel');

        return $answer === 'uninstall';
    }

    // ---- Step 4: teardown ----

    private function runTeardown(): bool
    {
        $this->newLine();
        $this->info('Removing package setup...');

        try {
            $this->packageMigrations->rollback(
                fn (string $line) => $this->line($line),
                fn (string $line) => $this->warn($line),
            );
        } catch (Throwable $e) {
            $this->error('Migration rollback failed: '.$e->getMessage());
            $this->newLine();
            $this->line('Some package tables may have been dropped and others may remain.');
            $this->line('Inspect your database and re-run once the cause is resolved.');

            return false;
        }

        try {
            $this->packageMigrations->deletePublishedFiles(
                fn (string $line) => $this->line($line),
            );
        } catch (Throwable $e) {
            $this->error('Deleting published migration files failed: '.$e->getMessage());
            $this->line('The package tables were rolled back, but some migration files in');
            $this->line('database/migrations/ may remain. Remove them manually.');

            return false;
        }

        try {
            if ($this->lockFile->exists()) {
                $this->lockFile->delete();
                $this->line('  - removed the public ID lock file');
            } else {
                $this->line('  - public ID lock file already absent');
            }
        } catch (Throwable $e) {
            $this->error('Deleting the lock file failed: '.$e->getMessage());
            $this->line('Tables and migration files were removed, but the lock file at');
            $this->line('  '.$this->displayPath($this->lockFile->path()));
            $this->line('could not be deleted. Remove it manually.');

            return false;
        }

        $this->teardownConfigFile();

        return true;
    }

    private function teardownConfigFile(): void
    {
        $paths = $this->publishedConfigPaths();

        // Default is to delete the published config files (auth.php AND the
        // dev-data config; full removal matches uninstall intent); --keep-config
        // preserves them for consumers who want their customizations back later.
        if ($this->option('keep-config')) {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    $this->line('  - kept the published config file ('.$this->displayPath($path).')');
                }
            }

            return;
        }

        $removedAny = false;
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
                $this->line('  - removed the published config file ('.$this->displayPath($path).')');
                $removedAny = true;
            }
        }

        if (! $removedAny) {
            $this->line('  - published config files already absent');
        }

        $this->removeConfigDirIfEmpty();
    }

    /**
     * Remove the vendor config directory (the parent of the published config
     * file, e.g. config/jamesgifford/) ONLY if it is now completely empty.
     *
     * The directory may be shared with other packages, so this is strictly
     * conditional: any remaining entry — another package's config, a committed
     * .gitkeep, a stray .DS_Store, a subdirectory — means we leave it untouched.
     * When in doubt, leave it: wrongly deleting a directory another package
     * depends on is far worse than leaving an empty one behind.
     */
    private function removeConfigDirIfEmpty(): void
    {
        $dir = dirname($this->publishedConfigPath());

        if (! is_dir($dir)) {
            return;
        }

        // scandir() lists ALL entries, including dotfiles like .gitkeep and
        // .DS_Store. The directory is "empty" only once nothing but . and ..
        // remains — a glob() that skips dotfiles would be unsafe here.
        $entries = array_diff(scandir($dir) ?: [], ['.', '..']);

        if ($entries !== []) {
            return;
        }

        if (@rmdir($dir)) {
            $this->configDirRemoved = true;
        }
    }

    // ---- Step 5: surgical User-model reversion ----

    /**
     * Surgically reverse the install modification of the User model: remove ONLY
     * the package's additions (HasPublicId/HasAccounts imports + trait usage and
     * the publicIdPrefix() method), preserving everything else. Falls back to
     * printed manual instructions when the model can't be safely auto-edited.
     * Also removes any pre-existing orphan `.bak` left by older installs.
     */
    private function revertUserModel(): void
    {
        $this->newLine();

        $file = $this->resolveUserModelFile();
        if ($file === null) {
            $this->line('Could not resolve your User model file from');
            $this->line('config(\'jamesgifford.auth.models.user\'). If you added the package\'s');
            $this->line('traits to a User model, remove HasPublicId and HasAccounts (and the');
            $this->line('publicIdPrefix() method) from it by hand.');

            return;
        }

        // Clean up any persistent backup left behind by older package versions.
        $this->removeOrphanBackup($file);

        $analysis = $this->modifier->analyze($file);

        $hasAnything = $analysis->hasHasPublicIdTrait
            || $analysis->hasHasAccountsTrait
            || $analysis->hasPublicIdPrefixMethod;

        if (! $hasAnything) {
            $this->line('Your User model no longer references the package\'s traits, so no');
            $this->line('changes are needed there.');

            return;
        }

        // Unusual structure (custom base, multiple classes, unparseable) — the
        // surgical editor refuses; fall back to manual instructions.
        if (! $analysis->isModifiable()) {
            $this->line('The package can\'t safely auto-edit your User model');
            $this->line('('.($analysis->unusualReason ?? 'unusual structure').'). Remove these by hand:');
            $this->printManualUserModelSteps($analysis);

            return;
        }

        $reversion = $this->modifier->reverseModify($file, $analysis);

        try {
            // Transient backup: created before the edit, restored on failure,
            // deleted on success — the model is never left with a stray .bak.
            $this->modifier->applyTransient(
                $file,
                $reversion->modifiedCode,
                verify: function () use ($file): void {
                    $check = $this->modifier->analyze($file);
                    if ($check->hasHasPublicIdTrait || $check->hasHasAccountsTrait || $check->hasPublicIdPrefixMethod) {
                        throw new RuntimeException('package additions were still present after reversion');
                    }
                },
            );
        } catch (Throwable $e) {
            $this->warn('Could not auto-revert your User model: '.$e->getMessage());
            $this->line('It was left unchanged. Remove the package additions by hand:');
            $this->printManualUserModelSteps($analysis);

            return;
        }

        $this->info('✓ Reverted your User model ('.$this->displayPath($file).'):');
        $removedTraits = [];
        if ($analysis->hasHasPublicIdTrait) {
            $removedTraits[] = 'HasPublicId';
        }
        if ($analysis->hasHasAccountsTrait) {
            $removedTraits[] = 'HasAccounts';
        }
        if ($removedTraits !== []) {
            $this->line('  • removed the '.implode(' and ', $removedTraits).' trait'.(count($removedTraits) === 1 ? '' : 's').' and their imports');
        }
        if ($reversion->removedPublicIdPrefixMethod) {
            if ($reversion->removedPrefixWasCustomized) {
                $this->line('  • removed your publicIdPrefix() method');
                $this->warn('    Note: that method contained custom logic — it has been removed as');
                $this->warn('    part of the full uninstall; re-add it elsewhere if you still need it.');
            } else {
                $value = $reversion->removedPrefixReturnValue;
                $this->line('  • removed the publicIdPrefix() method'.($value !== null ? " (it returned '{$value}')" : ''));
            }
        }
        $this->line('  All other code in the model was preserved. No backup file was left behind.');
    }

    /**
     * Print the by-hand removal steps for when auto-reversion isn't safe.
     */
    private function printManualUserModelSteps(UserModelAnalysis $analysis): void
    {
        $this->newLine();

        $imports = [];
        $traitNames = [];
        if ($analysis->hasHasPublicIdTrait) {
            $imports[] = 'use JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId;';
            $traitNames[] = 'HasPublicId';
        }
        if ($analysis->hasHasAccountsTrait) {
            $imports[] = 'use JamesGifford\\Auth\\Concerns\\HasAccounts;';
            $traitNames[] = 'HasAccounts';
        }

        if ($imports !== []) {
            $this->line('  • Remove the import'.(count($imports) === 1 ? '' : 's').':');
            foreach ($imports as $import) {
                $this->line('        '.$import);
            }
            $this->newLine();
            $this->line('  • Remove the trait'.(count($traitNames) === 1 ? '' : 's').' from the class:');
            $this->line('        use '.implode(', ', $traitNames).';');
            $this->newLine();
        }

        if ($analysis->hasPublicIdPrefixMethod) {
            $this->line('  • Remove the publicIdPrefix() method.');
        }
    }

    /**
     * Remove a persistent `User.php.bak` left behind by an older install (the
     * backup is no longer created or used — current edits are transient).
     */
    private function removeOrphanBackup(string $userModelFile): void
    {
        $backup = $userModelFile.'.bak';
        if (is_file($backup)) {
            @unlink($backup);
            $this->line('  - removed a leftover backup file ('.$this->displayPath($backup).')');
        }
    }

    // ---- Step 6: completion ----

    private function displayCompletion(): void
    {
        $this->newLine();
        $this->info('Uninstall complete.');
        $this->newLine();
        $this->line('  The package\'s tables, columns, migration files, public ID lock, and');
        $this->line('  User-model additions have been removed. Anything that needed your');
        $this->line('  attention (published models, an unusual User model) is noted above.');

        if ($this->configDirRemoved) {
            $this->newLine();
            $this->line('  '.$this->displayPath(dirname($this->publishedConfigPath())).' was empty and has been removed.');
        }
    }

    // ---- Helpers ----

    private function publishedConfigPath(): string
    {
        return config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
    }

    /**
     * Every config file the package publishes into the consumer's config dir:
     * the main config and the dev-data config. Both are removed on uninstall
     * (unless --keep-config).
     *
     * @return list<string>
     */
    private function publishedConfigPaths(): array
    {
        $dir = config_path('jamesgifford');

        return [
            $dir.DIRECTORY_SEPARATOR.'auth.php',
            $dir.DIRECTORY_SEPARATOR.'dev-data.php',
        ];
    }

    private function resolveUserModelFile(): ?string
    {
        $userClass = config('jamesgifford.auth.models.user');
        if (! is_string($userClass) || ! class_exists($userClass)) {
            return null;
        }

        $file = (new ReflectionClass($userClass))->getFileName();

        return $file === false ? null : $file;
    }

    /**
     * Render a path relative to the project root when it lives inside it, so
     * messages show config/jamesgifford/... rather than an absolute path.
     */
    private function displayPath(string $path): string
    {
        $base = $this->laravel->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function pluralize(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? $singular : $plural;
    }
}
