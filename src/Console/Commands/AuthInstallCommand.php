<?php

declare(strict_types=1);

namespace Progravity\Auth\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Progravity\Auth\Database\Seeders\AccountRoleSeeder;
use Progravity\Auth\Installer\UserModelModifier;
use Progravity\Auth\Models\AccountRole;
use Progravity\Auth\PublicId\Config\ConfigGuard;
use Progravity\Auth\PublicId\Config\GuardStatus;
use Progravity\Auth\SystemRole;
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
    protected $signature = 'progravity:auth:install
        {--skip-public-id : Skip public_id config setup}
        {--skip-migrations : Skip publishing and running migrations}
        {--skip-roles : Skip seeding system roles}
        {--skip-user-model : Skip User model modification; print instructions instead}
        {--no-modify-user : Alias for --skip-user-model}
        {--force : Bypass interactive prompts}
        {--verify : Only run verification; don\'t modify anything}';

    protected $description = 'Install and configure the Progravity Auth package in this application.';

    public function __construct(
        private readonly UserModelModifier $modifier,
        private readonly ConfigGuard $publicIdGuard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Progravity Auth Installer');
        $this->newLine();

        if ($this->option('verify')) {
            return $this->runVerification() ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->runPreflightChecks()) {
            return self::FAILURE;
        }

        $plan = $this->buildPlan();
        $this->displayPlan($plan);

        if (! $this->confirmPlan()) {
            $this->info('Installation canceled.');

            return self::SUCCESS;
        }

        if (! $this->executeInstall($plan)) {
            return self::FAILURE;
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
            $userClass = config('progravity.auth.models.user');

            if (! is_string($userClass) || ! class_exists($userClass)) {
                $this->error("User model class '{$userClass}' is not loadable. ".
                    "Verify config('progravity.auth.models.user') or run Laravel's auth scaffolding first.");
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
        $userClass = config('progravity.auth.models.user');
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
            'public_id_setup' => 'Lock public_id configuration (`progravity:public-id:setup`)',
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
        if ($this->option('force')) {
            return true;
        }

        return $this->confirm('Proceed?', true);
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
        $exit = $this->call('progravity:public-id:setup');

        return $exit === self::SUCCESS;
    }

    private function executePublishMigrations(): bool
    {
        $this->newLine();
        $this->info('→ Publishing package migrations...');
        $this->call('vendor:publish', ['--tag' => 'progravity-auth-migrations']);

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

        return true;
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
        $this->line('      use Progravity\\Auth\\PublicId\\Concerns\\HasPublicId;');
        $this->line('      use Progravity\\Auth\\Concerns\\HasAccounts;');
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
        $this->line('Then run `php artisan progravity:auth:install --verify` to confirm.');
    }

    // ---- Verification ----

    private function runVerification(): bool
    {
        $allOk = true;

        $check = function (string $label, bool $ok) use (&$allOk): void {
            $symbol = $ok ? '✓' : '✗';
            $this->line("  {$symbol} {$label}");
            if (! $ok) {
                $allOk = false;
            }
        };

        $check('Public ID configuration locked', $this->publicIdGuard->status() === GuardStatus::Locked);

        $check(
            'Package migrations published',
            glob(database_path('migrations'.DIRECTORY_SEPARATOR.'*_create_accounts_table.php')) !== [],
        );

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
                $analysis = $this->modifier->analyze($file);
                $userClass = config('progravity.auth.models.user');
                $check("{$userClass} uses HasPublicId trait", $analysis->hasHasPublicIdTrait);
                $check("{$userClass} uses HasAccounts trait", $analysis->hasHasAccountsTrait);
                $check("{$userClass} has publicIdPrefix() method", $analysis->hasPublicIdPrefixMethod);
            }
        }

        $this->newLine();
        $this->line($allOk ? 'All checks passed.' : 'One or more checks failed.');

        return $allOk;
    }

    // ---- Next steps ----

    private function displayNextSteps(): void
    {
        $this->info('Installation complete.');
        $this->newLine();
        $this->line('Next steps:');
        $this->newLine();
        $this->line('  1. Run your test suite to verify nothing else broke:');
        $this->line('       php artisan test');
        $this->newLine();
        $this->line('  2. Optionally customize the configuration:');
        $this->line('       config/progravity/auth.php');
        $this->newLine();
        $this->line('  3. Start using the package:');
        $this->line('       use Progravity\\Auth\\Accounts\\Services\\AccountService;');
        $this->line('       $account = app(AccountService::class)->create($user);');
    }
}
