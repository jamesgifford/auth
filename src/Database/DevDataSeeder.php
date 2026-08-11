<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Database;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Exceptions\DevDataSeedingNotAllowedException;
use JamesGifford\Auth\PackageModels;
use JamesGifford\Auth\PublicId\Concerns\HasPublicId;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\SystemRole;
use Throwable;

/**
 * Seeds deterministic development fixtures (users, accounts, memberships) from
 * config('jamesgifford.auth-dev'). Independent of the id-offset feature: it
 * does NOT call apply-id-offsets — the consumer orchestrates the order
 * (typically migrate → roles → seed dev data → apply-id-offsets).
 *
 * A first-class Laravel seeder: register it in a consuming app's DatabaseSeeder
 * with `$this->call(DevDataSeeder::class)`. The environment guard lives in
 * run(): outside an allowed environment (and ALWAYS in production) it logs a
 * notice and returns WITHOUT throwing, so `migrate:fresh --seed` in production
 * is safe regardless of how the seeder is invoked.
 *
 * The schema declares accounts explicitly and each user's memberships from that
 * user's own perspective:
 *
 *   'accounts' => [['name' => 'Acme Inc', 'owner' => 'owner@dev.test'], ...],
 *   'users'    => [
 *       ['name' => 'Owner', 'email' => 'owner@dev.test'],
 *       ['name' => 'Admin', 'email' => 'admin@dev.test',
 *           'memberships' => [['account' => 'Acme Inc', 'role' => 'admin']]],
 *       ['name' => 'Multi', 'email' => 'multi@dev.test',
 *           'memberships' => [['account' => 'Acme Inc', 'role' => 'member']],
 *           'current_account' => 'Beta LLC'],
 *   ],
 *
 * Accounts and memberships are created through the package's AccountService so
 * the single-owner invariant and events behave exactly as in normal use.
 */
final class DevDataSeeder extends Seeder
{
    public function __construct(
        private readonly Application $app,
        private readonly AccountService $accounts,
    ) {}

    /**
     * Seeder entry point (db:seed / DatabaseSeeder / `$this->call(...)`).
     *
     * The environment guard is here, at the top: outside an allowed environment
     * — and always in production — it logs a notice and returns without seeding
     * or throwing. This keeps `migrate:fresh --seed` safe in production no
     * matter which path invokes the seeder.
     */
    public function run(): void
    {
        if (! $this->environmentAllowed()) {
            Log::notice(sprintf(
                '[jamesgifford/auth] Skipping dev-data seeding: environment "%s" is not permitted '.
                '(allowed: %s; production is always refused).',
                $this->app->environment(),
                implode(', ', $this->allowedEnvironments()) ?: '(none)',
            ));

            return;
        }

        $this->seed();
    }

    /**
     * Whether the current environment may seed dev data: never production, and
     * otherwise only environments listed in the allowlist. Non-throwing — use
     * this to gate seeding; use {@see assertEnvironmentAllowed} when a hard,
     * message-carrying failure is wanted (e.g. an explicit console command).
     */
    public function environmentAllowed(): bool
    {
        $environment = $this->app->environment();

        if ($environment === 'production') {
            return false;
        }

        return in_array($environment, $this->allowedEnvironments(), true);
    }

    /**
     * Fails-closed environment guard that THROWS. Raised before any DB access.
     * Used by the console command, which wants a tailored failure message; the
     * seeder's own run() uses the non-throwing {@see environmentAllowed} guard.
     *
     * @throws DevDataSeedingNotAllowedException
     */
    public function assertEnvironmentAllowed(): void
    {
        $environment = $this->app->environment();

        // Independent, unconditional production refusal — holds even if the
        // allowlist were misconfigured to include 'production'.
        if ($environment === 'production') {
            throw DevDataSeedingNotAllowedException::production();
        }

        $allowed = $this->allowedEnvironments();
        if (! in_array($environment, $allowed, true)) {
            throw DevDataSeedingNotAllowedException::environmentNotAllowed($environment, $allowed);
        }
    }

    /**
     * @return array{users: int, accounts: int, memberships: int}
     *
     * @throws DevDataSeedingNotAllowedException when the environment is not permitted
     */
    public function seed(): array
    {
        // MUST come first — before any query — so a refused run touches nothing.
        $this->assertEnvironmentAllowed();

        /** @var array<string, mixed> $config */
        $config = (array) config('jamesgifford.auth-dev', []);

        // A leftover pre-1.2.2 'password' key is IGNORED (the key is now
        // 'users_password'); say so instead of silently seeding the default.
        // The console command prints a visible warning; this log covers
        // DatabaseSeeder-driven runs (`migrate:fresh --seed`).
        if ($this->legacyPasswordKeyPresent()) {
            Log::warning(
                "[jamesgifford/auth] The dev-data config contains the legacy 'password' key, which is ".
                "ignored — rename it to 'users_password' (read from JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD).",
            );
        }

        // Hash once. Models that cast password to 'hashed' won't re-hash an
        // already-hashed value (Hash::isHashed guard), so this is safe whether
        // or not the User model has that cast — and never stores plaintext.
        $password = Hash::make($this->resolveUsersPassword());

        $userClass = PackageModels::user();
        $accountClass = PackageModels::account();
        $accountUserClass = PackageModels::accountUser();

        // When the in-process User model lacks an ACTIVE HasPublicId trait — e.g.
        // setup's install step just added it to the file, but the already-loaded
        // class is stale and PHP can't reload it — its public_id won't
        // auto-populate. Detect that so we can set it explicitly and seeding
        // works in the SAME process as the model edit, instead of crashing with
        // "Field 'public_id' doesn't have a default value".
        $userAutoGeneratesPublicId = in_array(HasPublicId::class, class_uses_recursive($userClass), true);

        /** @var list<array<string, mixed>> $userDeclarations */
        $userDeclarations = $config['users'] ?? [];
        /** @var list<array<string, mixed>> $accountDeclarations */
        $accountDeclarations = $config['accounts'] ?? [];

        $counts = ['users' => 0, 'accounts' => 0, 'memberships' => 0];

        // Whether the users table has a public_id column is invariant across the
        // loop below, so resolve it once here rather than issuing a schema-
        // introspection query (information_schema / PRAGMA) per declared user.
        $probe = new $userClass;
        $publicIdColumnExists = $probe->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($probe->getTable(), 'public_id');

        // Pass 1: all users (idempotent on email) so accounts/memberships can
        // reference any of them regardless of declaration order.
        $usersByEmail = [];
        foreach ($userDeclarations as $declaration) {
            $email = (string) $declaration['email'];

            // firstOrNew keyed on email makes this idempotent: an existing user
            // (including one left over from before an uninstall — uninstall drops
            // the package columns but NOT the user rows) is FOUND and updated
            // rather than duplicated, so a re-seed never hits users_email_unique.
            $user = $userClass::query()->firstOrNew(['email' => $email]);
            $user->setAttribute('name', $declaration['name'] ?? $email);
            $user->setAttribute('password', $password);

            // Assign a public_id explicitly whenever the row would otherwise be
            // left without one and the trait can't fill it. That covers two
            // cases the trait's create-only hook misses:
            //   1. A NEW user under a stale, trait-less in-process User model
            //      (install just edited the file; PHP can't reload the class).
            //   2. An EXISTING leftover user with a null public_id — the column
            //      was dropped by uninstall and re-added, and the trait only
            //      generates on `creating`, never on the update of an existing
            //      row. (The re-add migration backfills these too, but the
            //      seeder stays correct on its own.)
            // Skipped only when the model DOES auto-generate AND the user is new
            // (the trait handles it on create), or the row already has an id.
            $needsExplicitPublicId = empty($user->getAttribute('public_id'))
                && $publicIdColumnExists
                && ($user->exists || ! $userAutoGeneratesPublicId);

            if ($needsExplicitPublicId) {
                $user->setAttribute('public_id', PublicId::generate($this->resolveUserPrefix($userClass)));
            }

            $user->save();

            $usersByEmail[$email] = $user;
            $counts['users']++;
        }

        // Pass 2: accounts, each created via the real service so the owner
        // membership and single-owner invariant are established. Keyed by name
        // so memberships/current_account below can reference them.
        $accountsByName = [];
        foreach ($accountDeclarations as $declaration) {
            $accountName = (string) ($declaration['name'] ?? '');
            $ownerEmail = (string) ($declaration['owner'] ?? '');
            $owner = $usersByEmail[$ownerEmail] ?? null;
            if ($accountName === '' || $owner === null) {
                continue;
            }

            $account = $accountClass::query()->where('name', $accountName)->first();
            if ($account === null) {
                $account = $this->accounts->create($owner, $accountName);
                $counts['accounts']++;
            }

            $accountsByName[$accountName] = $account;
        }

        // Pass 3: memberships declared from each user's perspective. Owner
        // memberships are already established by ownership (pass 2), and the
        // already-a-member check makes any accidental owner re-listing a no-op.
        foreach ($userDeclarations as $declaration) {
            $user = $usersByEmail[(string) $declaration['email']];

            /** @var list<array<string, mixed>> $memberships */
            $memberships = $declaration['memberships'] ?? [];
            foreach ($memberships as $membership) {
                $account = $accountsByName[(string) ($membership['account'] ?? '')] ?? null;
                if ($account === null) {
                    continue;
                }

                $alreadyMember = $accountUserClass::query()
                    ->where('account_id', $account->getKey())
                    ->where('user_id', $user->getKey())
                    ->exists();
                if ($alreadyMember) {
                    continue;
                }

                $this->accounts->attachUser($account, $user, (string) ($membership['role'] ?? SystemRole::MEMBER));
                $counts['memberships']++;
            }
        }

        // Pass 4: current account. Only set when explicitly declared AND the
        // user genuinely owns or belongs to the named account (validated), so a
        // typo can't point a user at an account they can't access.
        foreach ($userDeclarations as $declaration) {
            $currentAccountName = $declaration['current_account'] ?? null;
            if (! is_string($currentAccountName) || $currentAccountName === '') {
                continue;
            }

            $user = $usersByEmail[(string) $declaration['email']];
            $account = $accountsByName[$currentAccountName] ?? null;
            if ($account === null) {
                continue;
            }

            $isOwner = (int) $account->getAttribute('owner_id') === (int) $user->getKey();
            $isMember = $accountUserClass::query()
                ->where('account_id', $account->getKey())
                ->where('user_id', $user->getKey())
                ->exists();
            if (! $isOwner && ! $isMember) {
                continue;
            }

            $user->setAttribute('current_account_id', $account->getKey());
            $user->save();
        }

        return $counts;
    }

    /**
     * The shared plaintext password the cast will be seeded with (hashed at
     * seed time). Read from the live config when present; when the key is
     * MISSING there — the app booted with a config cached before the key
     * existed, so mergeConfigFrom never ran — re-read from disk: the published
     * file first, then the package default, whose env() call resolves the
     * CURRENT environment value even under a cached config. Mirrors the
     * staleness remedy install applies to role seeding.
     */
    public function resolveUsersPassword(): string
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('jamesgifford.auth-dev', []);

        if (array_key_exists('users_password', $config)) {
            return (string) $config['users_password'];
        }

        foreach ([
            config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth-dev.php'),
            dirname(__DIR__, 2).'/config/auth-dev.php',
        ] as $path) {
            if (! is_file($path)) {
                continue;
            }

            // require (not require_once) re-evaluates and returns the config
            // array each call, so this is immune to the boot-time include cache.
            $loaded = require $path;
            if (is_array($loaded) && array_key_exists('users_password', $loaded)) {
                return (string) $loaded['users_password'];
            }
        }

        return 'password';
    }

    /**
     * Whether the dev-data config still carries the pre-1.2.2 'password' key
     * (renamed to 'users_password'). Checked in the live config AND in the
     * published file on disk, so a stale published config is caught even when
     * the live config was built from the package default alone.
     */
    public function legacyPasswordKeyPresent(): bool
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('jamesgifford.auth-dev', []);

        if (array_key_exists('password', $config)) {
            return true;
        }

        $published = config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth-dev.php');
        if (! is_file($published)) {
            return false;
        }

        $loaded = require $published;

        return is_array($loaded) && array_key_exists('password', $loaded);
    }

    /**
     * @return list<string>
     */
    private function allowedEnvironments(): array
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('jamesgifford.auth-dev.environments', []);

        return $allowed;
    }

    /**
     * The public_id prefix for the user model, used only when its in-process
     * class can't auto-generate one. Falls back to 'user' if the model isn't
     * registered (its publicIdPrefix()/config entry is unavailable).
     */
    private function resolveUserPrefix(string $userClass): string
    {
        try {
            return $this->app->make(PrefixRegistry::class)->prefixFor($userClass);
        } catch (Throwable) {
            return 'user';
        }
    }
}
