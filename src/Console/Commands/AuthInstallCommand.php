<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Installer\PackageMigrations;
use JamesGifford\Auth\Installer\UserModelModifier;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\PublicId\AlphabetRegistry;
use JamesGifford\Auth\PublicId\Config\ConfigFingerprint;
use JamesGifford\Auth\PublicId\Config\ConfigGuard;
use JamesGifford\Auth\PublicId\Config\GuardStatus;
use JamesGifford\Auth\PublicId\Config\LockFile;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Generator;
use JamesGifford\Auth\PublicId\Validator;
use JamesGifford\Auth\SystemRole;
use ReflectionClass;
use Throwable;

/**
 * One-shot interactive installer that gets the package wired into a fresh
 * Laravel application. Each major step has a --skip-* flag for granular
 * opt-out, and --force runs the whole thing non-interactively. --verify
 * runs only the verification step without making changes.
 */
final class AuthInstallCommand extends Command
{
    protected $signature = 'jamesgifford:auth:install
        {--skip-public-id : Skip public_id config setup}
        {--skip-migrations : Skip publishing and running migrations}
        {--skip-roles : Skip seeding system roles}
        {--skip-user-model : Skip User model modification; print instructions instead}
        {--no-modify-user : Alias for --skip-user-model}
        {--force : Bypass interactive prompts}
        {--fresh : Tear down and cleanly redo the package setup (development only; refuses if package data exists)}
        {--without-http : Disable the HTTP plumbing (routes + middleware); sets http.enabled = false in the published config}
        {--verify : Only run verification; don\'t modify anything}';

    protected $description = 'Install and configure the JamesGifford Auth package in this application.';

    public function __construct(
        private readonly UserModelModifier $modifier,
        private readonly ConfigGuard $publicIdGuard,
        private readonly PackageMigrations $packageMigrations,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('JamesGifford Auth Installer');
        $this->newLine();

        if ($this->option('verify')) {
            return $this->runVerification() ? self::SUCCESS : self::FAILURE;
        }

        // --fresh tears down the package's setup before the normal (additive)
        // install flow redoes it from current config. The preamble may refuse
        // (returns FAILURE) or be canceled (returns SUCCESS); either way it
        // short-circuits. A null return means teardown succeeded — fall through.
        if ($this->option('fresh')) {
            $stop = $this->runFreshPreamble();
            if ($stop !== null) {
                return $stop;
            }
        }

        if (! $this->runPreflightChecks()) {
            return self::FAILURE;
        }

        // Make sure the config file is published (and read fresh) before we
        // build the plan from it or display it.
        $this->ensureConfigPublished();

        // HTTP plumbing is on by default; --without-http turns it off in the
        // published config so the service provider skips route/middleware
        // registration.
        if ($this->option('without-http')) {
            $this->disableHttpPlumbing();
        }

        $plan = $this->buildPlan();
        $this->displayPlan($plan);

        if (! $this->confirmPlan()) {
            $this->info('Installation canceled.');

            return self::SUCCESS;
        }

        // Surface the configuration that will be locked, and confirm it, before
        // the irreversible public_id lock. Only when we are actually going to
        // lock (a re-run with the config already locked has nothing to gate).
        if ($plan['public_id_setup'] && ! $this->confirmPublicIdConfig()) {
            $this->info('Setup canceled. Edit config/jamesgifford/auth.php and re-run.');

            return self::SUCCESS;
        }

        if (! $this->executeInstall($plan)) {
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->warnPublicIdPrefixMismatch();
        }

        $this->newLine();
        $this->info('Verifying installation...');
        $this->newLine();
        if (! $this->runVerification()) {
            $this->error('Verification failed. See above for details.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->displayNextSteps();

        return self::SUCCESS;
    }

    // ---- Pre-flight ----

    private function runPreflightChecks(): bool
    {
        $ok = true;

        if (! $this->shouldSkipUserModel()) {
            $userClass = config('jamesgifford.auth.models.user');

            if (! is_string($userClass) || ! class_exists($userClass)) {
                $this->error("User model class '{$userClass}' is not loadable. ".
                    "Verify config('jamesgifford.auth.models.user') or run Laravel's auth scaffolding first.");
                $ok = false;
            } else {
                $reflection = new ReflectionClass($userClass);
                $file = $reflection->getFileName();
                if ($file !== false && str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                    $this->error("User model {$userClass} is defined in vendor/ ({$file}). ".
                        'The installer refuses to modify vendor files; subclass it in your app/ directory instead.');
                    $ok = false;
                }
            }
        }

        if (! Schema::hasTable('users')) {
            $this->error("The 'users' table does not exist. The package's migration alters this table; run Laravel's default user migration first.");
            $ok = false;
        }

        // Conflicting columns on `users`. Only an issue if migrations haven't
        // been run yet (post-run, these columns are EXPECTED to exist because
        // the package's migration added them).
        if (Schema::hasTable('users') && ! $this->packageMigrationsHaveRun()) {
            if (Schema::hasColumn('users', 'public_id')) {
                $this->error("The 'users' table already has a 'public_id' column from another source. ".
                    'Resolve the conflict before installing (rename or drop the existing column).');
                $ok = false;
            }
            if (Schema::hasColumn('users', 'current_account_id')) {
                $this->error("The 'users' table already has a 'current_account_id' column from another source. ".
                    'Resolve the conflict before installing.');
                $ok = false;
            }
        }

        return $ok;
    }

    // ---- Plan ----

    /**
     * @return array<string, bool>
     */
    private function buildPlan(): array
    {
        return [
            'public_id_setup' => $this->needsPublicIdSetup() && ! $this->option('skip-public-id'),
            'publish_migrations' => $this->needsMigrationsPublished() && ! $this->option('skip-migrations'),
            'run_migrations' => $this->needsMigrationsRun() && ! $this->option('skip-migrations'),
            'seed_roles' => $this->needsRolesSeeded() && ! $this->option('skip-roles'),
            'modify_user_model' => $this->needsUserModelModification() && ! $this->shouldSkipUserModel(),
        ];
    }

    private function shouldSkipUserModel(): bool
    {
        return (bool) ($this->option('skip-user-model') || $this->option('no-modify-user'));
    }

    private function needsPublicIdSetup(): bool
    {
        return $this->publicIdGuard->status() !== GuardStatus::Locked;
    }

    private function needsMigrationsPublished(): bool
    {
        $glob = database_path('migrations'.DIRECTORY_SEPARATOR.'*_create_accounts_table.php');

        return glob($glob) === [];
    }

    private function needsMigrationsRun(): bool
    {
        return ! Schema::hasTable('users') || ! Schema::hasColumn('users', 'public_id');
    }

    private function needsRolesSeeded(): bool
    {
        if (! Schema::hasTable('account_roles')) {
            return true;
        }

        return AccountRole::findByKey(SystemRole::OWNER) === null;
    }

    private function packageMigrationsHaveRun(): bool
    {
        return Schema::hasColumn('users', 'public_id')
            || Schema::hasTable('accounts');
    }

    private function needsUserModelModification(): bool
    {
        $file = $this->resolveUserModelFile();
        if ($file === null) {
            return false;
        }

        return $this->modifier->analyze($file)->needsModification();
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

    // ---- Plan display ----

    /**
     * @param  array<string, bool>  $plan
     */
    private function displayPlan(array $plan): void
    {
        $this->info('The installer will perform the following steps:');
        $this->newLine();

        $rows = [
            'public_id_setup' => 'Lock public_id configuration format',
            'publish_migrations' => 'Publish package migrations to database/migrations/',
            'run_migrations' => 'Run pending migrations',
            'seed_roles' => 'Seed system roles into account_roles',
            'modify_user_model' => 'Modify your User model to add HasPublicId and HasAccounts traits',
        ];

        $i = 1;
        foreach ($rows as $key => $label) {
            if ($plan[$key]) {
                $this->line(sprintf('  %d. → %s', $i, $label));
            } else {
                $reason = $this->skipReason($key);
                $this->line(sprintf('  %d. ⊘ %s (%s)', $i, $label, $reason));
            }
            $i++;
        }

        if ($plan['modify_user_model']) {
            $this->newLine();
            $this->line('A backup of the User model will be saved alongside the original (.bak) before modification.');
        }

        $this->newLine();
    }

    private function skipReason(string $stepKey): string
    {
        return match ($stepKey) {
            'public_id_setup' => $this->option('skip-public-id') ? 'skipped via flag' : 'already locked',
            'publish_migrations' => $this->option('skip-migrations') ? 'skipped via flag' : 'already published',
            'run_migrations' => $this->option('skip-migrations') ? 'skipped via flag' : 'already run',
            'seed_roles' => $this->option('skip-roles') ? 'skipped via flag' : 'already seeded',
            'modify_user_model' => $this->shouldSkipUserModel() ? 'skipped via flag' : 'already configured',
            default => 'skipped',
        };
    }

    private function confirmPlan(): bool
    {
        // --fresh already obtained confirmation (or --force) in its preamble;
        // don't prompt a second time for the redo.
        if ($this->option('force') || $this->option('fresh')) {
            return true;
        }

        return $this->confirm('Proceed?', true);
    }

    // ---- Configuration surfacing ----

    private function publishedConfigPath(): string
    {
        return config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
    }

    /**
     * Turn off the HTTP plumbing by setting http.enabled = false, both in the
     * live config (for this process) and in the published config file (so the
     * service provider skips route/middleware registration on future requests).
     */
    private function disableHttpPlumbing(): void
    {
        config(['jamesgifford.auth.http.enabled' => false]);

        $path = $this->publishedConfigPath();
        if (! is_file($path)) {
            $this->line('HTTP plumbing disabled for this run (no published config file to update).');

            return;
        }

        $contents = (string) file_get_contents($path);

        // Flip the http block's `enabled` to false. The lazy match is anchored
        // to the 'http' key, so it targets http.enabled and not the unrelated
        // checksum.enabled earlier in the file.
        $updated = preg_replace(
            "/('http'\\s*=>\\s*\\[.*?'enabled'\\s*=>\\s*)true/s",
            '${1}false',
            $contents,
            1,
        );

        if (is_string($updated) && $updated !== $contents) {
            file_put_contents($path, $updated);
            $this->callSilent('config:clear');
            $this->line('HTTP plumbing disabled (set http.enabled = false in config/jamesgifford/auth.php).');

            return;
        }

        $this->line('HTTP plumbing disabled for this run; update http.enabled in config/jamesgifford/auth.php manually to persist it.');
    }

    /**
     * Publish the package config if the consumer hasn't already. No prompt —
     * just publish the defaults and continue. If it already exists (the
     * consumer may have edited it), leave it untouched.
     */
    private function ensureConfigPublished(): void
    {
        if (is_file($this->publishedConfigPath())) {
            return;
        }

        $this->callSilent('vendor:publish', ['--tag' => 'jamesgifford-auth-config']);

        // The merged config was resolved at boot, before the file existed; clear
        // any cached config so subsequent reads reflect the published file.
        // (Same in-process staleness remedy as the verification step.)
        $this->callSilent('config:clear');

        $this->line('Published configuration to config/jamesgifford/auth.php');
    }

    /**
     * Concise, look-before-you-leap summary of the public_id format settings
     * that are about to be locked, plus the role list and a genuine sample ID.
     * Every value is pulled from resolved config (not hardcoded). Shared by the
     * normal install path and the --fresh path.
     */
    private function displayPublicIdConfig(): void
    {
        // The config singletons were built at boot; rebuild them so the display
        // reflects the current (possibly just-published or edited) config.
        foreach ([PublicIdConfig::class, Generator::class, Validator::class] as $abstract) {
            $this->laravel->forgetInstance($abstract);
        }

        $config = $this->laravel->make(PublicIdConfig::class);
        $generator = $this->laravel->make(Generator::class);

        $this->newLine();
        $this->info('Configuration (from config/jamesgifford/auth.php):');
        $this->newLine();
        $this->line('  Public ID format');
        $this->line(sprintf('    Prefix max length   %d', $config->prefixMaxLength()));
        $this->line(sprintf('    Body length         %d', $config->bodyLength()));
        $this->line('    Alphabet            '.$this->describeAlphabet($config));
        $this->line('    Checksum            '.$this->describeChecksum($config));
        $this->line('    Example             '.$generator->generate($this->sampleExamplePrefix($config)));
        $this->newLine();
        $this->line('  Account roles         '.$this->describeRoles());
        $this->newLine();
        $this->line('  The public ID format above will be locked at setup and cannot be');
        $this->line('  changed afterward without invalidating any IDs already created.');
        $this->newLine();
        $this->line('  To use different settings, edit config/jamesgifford/auth.php and re-run.');
    }

    /**
     * Display the config and prompt to proceed (normal install path). Under
     * --force the display still prints (for the log) but the prompt is skipped.
     * Returns false when the consumer declines.
     */
    private function confirmPublicIdConfig(): bool
    {
        $this->displayPublicIdConfig();

        if ($this->option('force')) {
            return true;
        }

        $this->newLine();

        return $this->confirm('Proceed with this configuration?', true);
    }

    private function describeAlphabet(PublicIdConfig $config): string
    {
        $value = $config->bodyAlphabetConfigValue();
        $size = $config->bodyAlphabet()->size();

        // Named preset → human name + count. Raw string → the (truncated)
        // string + count. Mirrors the setup wizard's named-vs-raw distinction.
        if ($this->laravel->make(AlphabetRegistry::class)->has($value)) {
            return sprintf('%s (%d characters)', $value, $size);
        }

        $shown = mb_strlen($value) > 32 ? mb_substr($value, 0, 31).'…' : $value;

        return sprintf('%s (%d characters)', $shown, $size);
    }

    private function describeChecksum(PublicIdConfig $config): string
    {
        if (! $config->checksumEnabled()) {
            return 'disabled';
        }

        return sprintf('enabled (%d characters)', $config->checksumLength());
    }

    private function describeRoles(): string
    {
        $roles = config('jamesgifford.auth.roles', []);
        $keys = is_array($roles) ? array_keys($roles) : [];

        return $keys === [] ? '(none configured)' : implode(', ', $keys);
    }

    /**
     * A representative prefix that fits within the configured prefix length,
     * so the example ID is a genuine, valid sample of the configured format.
     */
    private function sampleExamplePrefix(PublicIdConfig $config): string
    {
        $max = $config->prefixMaxLength();

        return $max >= 3 ? 'acc' : substr('acc', 0, max(1, $max));
    }

    // ---- Execution ----

    /**
     * @param  array<string, bool>  $plan
     */
    private function executeInstall(array $plan): bool
    {
        if ($plan['public_id_setup']) {
            if (! $this->executePublicIdSetup()) {
                return false;
            }
        }
        if ($plan['publish_migrations']) {
            if (! $this->executePublishMigrations()) {
                return false;
            }
        }
        if ($plan['run_migrations']) {
            if (! $this->executeRunMigrations()) {
                return false;
            }
        }
        if ($plan['seed_roles']) {
            if (! $this->executeSeedRoles()) {
                return false;
            }
        }
        if ($plan['modify_user_model']) {
            if (! $this->executeModifyUserModel()) {
                return false;
            }
        }

        return true;
    }

    private function executePublicIdSetup(): bool
    {
        $this->newLine();
        $this->info('→ Locking public_id configuration...');

        // The configuration was already displayed and confirmed (see
        // confirmPublicIdConfig in handle()), so write the lock directly rather
        // than delegating to the interactive setup wizard, which would display
        // and prompt a second time.
        return $this->writePublicIdLock();
    }

    private function executePublishMigrations(): bool
    {
        $this->newLine();
        $this->info('→ Publishing package migrations...');
        $this->call('vendor:publish', ['--tag' => 'jamesgifford-auth-migrations']);

        $published = glob(database_path('migrations'.DIRECTORY_SEPARATOR.'*_create_accounts_table.php'));
        if ($published === []) {
            $this->error('Migrations did not appear in database/migrations/ after publish.');

            return false;
        }

        return true;
    }

    private function executeRunMigrations(): bool
    {
        $this->newLine();
        $this->info('→ Running pending migrations...');
        $exit = $this->call('migrate', ['--force' => true]);

        return $exit === self::SUCCESS;
    }

    private function executeSeedRoles(): bool
    {
        $this->newLine();
        $this->info('→ Seeding system roles...');

        // AccountRoleSeeder reads config('jamesgifford.auth.roles'). If the
        // consuming app had its config cached (so mergeConfigFrom was skipped),
        // or the config was just published in this same process, the live config
        // repository can be stale/empty even though the file on disk is correct.
        // Clear any cached config and re-read the roles from disk so the seeder
        // never runs against an empty roles map. Mirrors the staleness remedy
        // already applied to verification and the config display.
        $this->callSilent('config:clear');
        $this->ensureRolesConfigLoaded();

        try {
            $this->laravel->make(AccountRoleSeeder::class)->run();
        } catch (Throwable $e) {
            $this->error('Role seeding failed: '.$e->getMessage());

            return false;
        }

        if (AccountRole::findByKey(SystemRole::OWNER) === null) {
            $this->error('Owner role still missing after seeding.');

            return false;
        }

        $roleKeys = array_keys((array) config('jamesgifford.auth.roles', []));
        $this->line(sprintf(
            '  Seeded %d account %s (%s).',
            count($roleKeys),
            count($roleKeys) === 1 ? 'role' : 'roles',
            implode(', ', $roleKeys),
        ));

        return true;
    }

    /**
     * Guarantee config('jamesgifford.auth.roles') reflects the real roles before
     * seeding. When the live config is empty (cached/stale in-process config),
     * re-read the roles straight from the published config on disk — or the
     * package default — so seeding uses the actual values, not stale defaults.
     */
    private function ensureRolesConfigLoaded(): void
    {
        $roles = config('jamesgifford.auth.roles');
        if (is_array($roles) && $roles !== []) {
            return;
        }

        foreach ([$this->publishedConfigPath(), __DIR__.'/../../../config/auth.php'] as $path) {
            if (! is_file($path)) {
                continue;
            }

            // require (not require_once) re-evaluates and returns the config
            // array each call, so this is immune to the boot-time include cache.
            $loaded = require $path;
            if (is_array($loaded) && isset($loaded['roles']) && is_array($loaded['roles']) && $loaded['roles'] !== []) {
                config(['jamesgifford.auth.roles' => $loaded['roles']]);

                return;
            }
        }
    }

    private function executeModifyUserModel(): bool
    {
        $this->newLine();
        $this->info('→ Modifying User model...');

        $file = $this->resolveUserModelFile();
        if ($file === null) {
            $this->error('Could not resolve User model file path.');

            return false;
        }

        $analysis = $this->modifier->analyze($file);

        if (! $analysis->isModifiable()) {
            $this->warn("Automatic modification is not safe for this User model: {$analysis->unusualReason}.");
            $this->displayManualInstructions($file);

            return true;
        }

        if (! $analysis->needsModification()) {
            $this->info('User model is already configured. Nothing to do.');

            return true;
        }

        $modification = $this->modifier->modify($file, $analysis);

        $this->newLine();
        $this->line('Proposed changes:');
        $this->newLine();
        $this->line($modification->diff());
        $this->newLine();

        if (! $this->option('force')) {
            if (! $this->confirm('Apply these changes?', true)) {
                $this->info('User model modification skipped. Run with --verify after applying changes manually.');

                return true;
            }
        }

        try {
            $this->modifier->write($file, $modification, createBackup: true);
        } catch (Throwable $e) {
            $this->error('Failed to write modified User model: '.$e->getMessage());

            try {
                $this->modifier->restore($file);
                $this->line('Restored from backup.');
            } catch (Throwable) {
                // best effort
            }

            return false;
        }

        $this->info('✓ User model updated. Backup saved to '.$file.'.bak');

        return true;
    }

    private function displayManualInstructions(string $file): void
    {
        $this->newLine();
        $this->line('To complete setup manually, add the following to your User model');
        $this->line("(at {$file}):");
        $this->newLine();
        $this->line('  Add to the use statements at the top of the file:');
        $this->newLine();
        $this->line('      use JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId;');
        $this->line('      use JamesGifford\\Auth\\Concerns\\HasAccounts;');
        $this->newLine();
        $this->line('  Add to the class body, after the existing traits:');
        $this->newLine();
        $this->line('      use HasPublicId, HasAccounts;');
        $this->newLine();
        $this->line('  Add the following method:');
        $this->newLine();
        $this->line('      public function publicIdPrefix(): string');
        $this->line('      {');
        $this->line("          return 'usr';");
        $this->line('      }');
        $this->newLine();
        $this->line('Then run `php artisan jamesgifford:auth:install --verify` to confirm.');
    }

    // ---- Fresh mode ----

    /**
     * Guard, confirm, and tear down before the redo. Returns null to proceed
     * (teardown + re-lock done), self::FAILURE to refuse, or self::SUCCESS when
     * the consumer cancels at the confirmation prompt.
     */
    private function runFreshPreamble(): ?int
    {
        if ($this->laravel->environment() === 'production') {
            $this->error('--fresh refuses to run in production.');
            $this->newLine();
            $this->line('In production you should evolve the schema with new migrations, not');
            $this->line('tear it down. Generate a migration for your change and run:');
            $this->newLine();
            $this->line('  php artisan migrate');

            return self::FAILURE;
        }

        $data = $this->packageDataExists();
        if ($data !== []) {
            $this->error('--fresh refuses to run: package-owned data exists.');
            $this->newLine();
            foreach ($data as $line) {
                $this->line('  • '.$line);
            }
            $this->newLine();
            $this->line('A fresh reinstall would orphan or destroy this data.');
            $this->newLine();
            $this->line('To remove the package when data exists, use the dedicated teardown');
            $this->line('command `jamesgifford:auth:uninstall` (note: not yet available in this');
            $this->line('version). Otherwise back up your data and reset manually before retrying.');

            return self::FAILURE;
        }

        // Surface the configuration that will be re-locked from the (possibly
        // edited) config file, and confirm it BEFORE any teardown — declining
        // must leave the existing install untouched.
        $this->ensureConfigPublished();
        $this->displayPublicIdConfig();

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('--fresh will roll back the package\'s migrations, delete the published');
            $this->line('migration files, reset the public ID configuration lock, and redo the');
            $this->line('setup from this configuration. No package data exists, so this is safe.');
            $this->newLine();

            if (! $this->confirm('Proceed with this configuration?', false)) {
                $this->info('Fresh reinstall canceled. Edit config/jamesgifford/auth.php and re-run.');

                return self::SUCCESS;
            }
        }

        $this->newLine();
        $this->info('→ Tearing down existing package setup...');
        $this->packageMigrations->rollback(
            fn (string $line) => $this->line($line),
            fn (string $line) => $this->warn($line),
        );
        $this->packageMigrations->deletePublishedFiles(
            fn (string $line) => $this->line($line),
        );
        $this->resetPublicIdLock();

        $this->newLine();
        $this->info('→ Re-locking public ID configuration from current config...');
        if (! $this->writePublicIdLock()) {
            return self::FAILURE;
        }

        return null;
    }

    /**
     * Structured description of any package-owned data that exists. An empty
     * array means it is safe to tear down and redo.
     *
     * @return list<string>
     */
    private function packageDataExists(): array
    {
        $found = [];

        if (Schema::hasTable('accounts')) {
            $count = DB::table('accounts')->count();
            if ($count > 0) {
                $found[] = "the 'accounts' table has {$count} row(s)";
            }
        }

        if (Schema::hasTable('account_user')) {
            $count = DB::table('account_user')->count();
            if ($count > 0) {
                $found[] = "the 'account_user' table has {$count} membership row(s)";
            }
        }

        if (Schema::hasTable('account_roles')) {
            $custom = DB::table('account_roles')->where('system', false)->count();
            if ($custom > 0) {
                $found[] = "the 'account_roles' table has {$custom} custom (non-system) role(s)";
            }
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'public_id')) {
            $withPublicId = DB::table('users')->whereNotNull('public_id')->count();
            if ($withPublicId > 0) {
                $found[] = "{$withPublicId} user(s) have a non-null public_id";
            }
        }

        return $found;
    }

    /**
     * Delete the public ID lock file (no IDs exist per the data check, so this
     * is safe). Equivalent to what jamesgifford:public-id:reset does.
     */
    private function resetPublicIdLock(): void
    {
        try {
            $this->laravel->make(LockFile::class)->delete();
            $this->line('  - reset public ID configuration lock');
        } catch (Throwable $e) {
            $this->warn('  - could not delete the lock file: '.$e->getMessage());
        }
    }

    /**
     * Write the public_id lock file from the CURRENT (possibly edited) config,
     * resolving fresh config/lock singletons so edits are picked up. Shared by
     * the normal install path and the --fresh redo.
     */
    private function writePublicIdLock(): bool
    {
        foreach ([PublicIdConfig::class, LockFile::class, ConfigFingerprint::class, ConfigGuard::class] as $abstract) {
            $this->laravel->forgetInstance($abstract);
        }

        $config = $this->laravel->make(PublicIdConfig::class);
        $lockFile = $this->laravel->make(LockFile::class);
        $fingerprint = $this->laravel->make(ConfigFingerprint::class);

        try {
            $lockFile->write($config, $fingerprint->compute($config));
        } catch (Throwable $e) {
            $this->error('Failed to lock public ID configuration: '.$e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Advisory (not an error): if config maps the User model to a different
     * prefix than the model's publicIdPrefix() actually returns, tell the
     * consumer — --fresh deliberately does not rewrite the User model.
     */
    private function warnPublicIdPrefixMismatch(): void
    {
        $userClass = config('jamesgifford.auth.models.user');
        if (! is_string($userClass) || ! class_exists($userClass)) {
            return;
        }

        $configPrefixes = config('jamesgifford.auth.public_id.prefixes', []);
        if (! is_array($configPrefixes) || ! array_key_exists($userClass, $configPrefixes)) {
            // No config-implied user prefix to compare against.
            return;
        }
        $configPrefix = $configPrefixes[$userClass];

        try {
            $modelPrefix = (new $userClass)->publicIdPrefix();
        } catch (Throwable) {
            return;
        }

        if ($modelPrefix === $configPrefix) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf(
            "Your User model's publicIdPrefix() returns '%s', but config maps %s to '%s'.",
            $modelPrefix,
            $userClass,
            (string) $configPrefix,
        ));
        $this->line('If you intended to change the user prefix, update app/Models/User.php manually.');
        $this->line('--fresh does not modify your User model.');
    }

    // ---- Verification ----

    private function runVerification(): bool
    {
        // Verification runs in the same process that just wrote the lock file,
        // ran migrations, and modified the User model file. Stale in-process
        // state would produce false negatives, so refresh first:
        //  - config:clear drops any cached config so config()-backed checks
        //    read current values.
        //  - A freshly-resolved ConfigGuard re-reads the lock file from disk
        //    (the injected guard may have memoized "not yet locked" before the
        //    lock was written during this same run).
        $this->callSilent('config:clear');
        $guard = $this->freshPublicIdGuard();

        /** @var list<string> $failures */
        $failures = [];

        $check = function (string $label, bool $ok) use (&$failures): void {
            $this->line('  '.($ok ? '✓' : '✗')." {$label}");
            if (! $ok) {
                $failures[] = $label;
            }
        };

        $check('Public ID configuration locked', $guard->status() === GuardStatus::Locked);

        $check(
            'Package migrations published',
            glob(database_path('migrations'.DIRECTORY_SEPARATOR.'*_create_accounts_table.php')) !== [],
        );

        // Schema::has* issues a fresh query per call (no per-process memoization
        // is added by this command), so these reflect the current schema.
        $check('users.public_id column exists', Schema::hasColumn('users', 'public_id'));
        $check('users.current_account_id column exists', Schema::hasColumn('users', 'current_account_id'));
        $check('accounts table exists', Schema::hasTable('accounts'));
        $check('account_roles table exists', Schema::hasTable('account_roles'));
        $check('account_user table exists', Schema::hasTable('account_user'));

        $rolesOk = Schema::hasTable('account_roles') && AccountRole::findByKey(SystemRole::OWNER) !== null;
        $check('System roles seeded (owner role present)', $rolesOk);

        if (! $this->shouldSkipUserModel()) {
            $file = $this->resolveUserModelFile();
            if ($file === null) {
                $check('User model is loadable', false);
            } else {
                // Re-analyze the file from disk via php-parser rather than
                // reflecting on the already-loaded class. PHP caches the class
                // definition from its first autoload, so reflection would
                // report the pre-modification shape even though the file now
                // has the traits. analyze() reads current file content.
                $analysis = $this->modifier->analyze($file);
                $userClass = config('jamesgifford.auth.models.user');
                $check("{$userClass} uses HasPublicId trait", $analysis->hasHasPublicIdTrait);
                $check("{$userClass} uses HasAccounts trait", $analysis->hasHasAccountsTrait);
                $check("{$userClass} has publicIdPrefix() method", $analysis->hasPublicIdPrefixMethod);
            }
        }

        $this->newLine();

        if ($failures !== []) {
            $this->line('The following checks failed:');
            foreach ($failures as $label) {
                $this->line('  ✗ '.$label);
            }
            $this->newLine();
            $this->line('One or more checks failed.');

            return false;
        }

        $this->line('All checks passed.');

        return true;
    }

    /**
     * Resolve a ConfigGuard that reflects current on-disk + config state.
     *
     * The guard (and the config/lock-file singletons it depends on) memoize
     * their state on first use. During a full install run that happens before
     * the lock file is written, so the injected instance can report a stale
     * "not yet locked". Forgetting and re-resolving forces a fresh read.
     */
    private function freshPublicIdGuard(): ConfigGuard
    {
        foreach ([ConfigGuard::class, PublicIdConfig::class, LockFile::class, ConfigFingerprint::class] as $abstract) {
            $this->laravel->forgetInstance($abstract);
        }

        return $this->laravel->make(ConfigGuard::class);
    }

    // ---- Next steps ----

    private function displayNextSteps(): void
    {
        $this->info('Installation complete.');
        $this->newLine();

        // Resolve the actual lock file path the same way the running command
        // does, so a customized lock_file_path is reflected here. Display it
        // relative to the project root when it lives inside it, so the note
        // shows config/jamesgifford/auth.lock.json rather than an absolute path.
        $lockPath = $this->laravel->make(LockFile::class)->path();
        $base = $this->laravel->basePath().DIRECTORY_SEPARATOR;
        $displayPath = str_starts_with($lockPath, $base)
            ? substr($lockPath, strlen($base))
            : $lockPath;

        $this->line('  The public ID format is now locked. This lock is recorded in:');
        $this->newLine();
        $this->line('      '.$displayPath);
        $this->newLine();
        $this->line('  The package checks this file to confirm the public ID format');
        $this->line("  hasn't changed unexpectedly. Commit it to version control so it");
        $this->line('  stays consistent across your environments.');

        $this->displayBoostReminder();
    }

    /**
     * Soft, conditional pointer for Laravel Boost users. This does NOT detect,
     * require, or invoke Boost — it only prints text. Consumers who don't use
     * Boost can ignore it.
     */
    private function displayBoostReminder(): void
    {
        $this->newLine();
        $this->line('  Using Laravel Boost? Run `php artisan boost:update` to install this');
        $this->line("  package's AI skill, which teaches coding agents its auth conventions.");
        $this->line('  (First-time Boost setup uses `php artisan boost:install`.) If you');
        $this->line("  don't use Boost, no action is needed.");
    }
}
