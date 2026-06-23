<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Database;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Exceptions\DevDataSeedingNotAllowedException;
use JamesGifford\Auth\SystemRole;

/**
 * Seeds deterministic LOCAL dev fixtures (users, accounts, memberships) from
 * config('jamesgifford.dev-data'). Independent of the id-offset feature: it
 * does NOT call apply-id-offsets — the consumer orchestrates the order
 * (typically migrate → roles → seed-dev-data → apply-id-offsets).
 *
 * Environment is fails-closed: only allow-listed environments may seed, and
 * 'production' is refused unconditionally — checked BEFORE any database access,
 * so a refused run changes nothing.
 *
 * Accounts and memberships are created through the package's AccountService so
 * the single-owner invariant and events behave exactly as in normal use.
 */
final class DevDataSeeder
{
    public function __construct(
        private readonly Application $app,
        private readonly AccountService $accounts,
    ) {}

    /**
     * Fails-closed environment guard. Raised before any DB access.
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

        /** @var list<string> $allowed */
        $allowed = (array) config('jamesgifford.dev-data.environments', []);
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
        $config = (array) config('jamesgifford.dev-data', []);

        // Hash once. Models that cast password to 'hashed' won't re-hash an
        // already-hashed value (Hash::isHashed guard), so this is safe whether
        // or not the User model has that cast — and never stores plaintext.
        $password = Hash::make((string) ($config['password'] ?? 'password'));

        /** @var class-string<Model> $userClass */
        $userClass = config('jamesgifford.auth.models.user');

        /** @var list<array<string, mixed>> $declarations */
        $declarations = $config['users'] ?? [];

        $counts = ['users' => 0, 'accounts' => 0, 'memberships' => 0];

        // Pass 1: all users (idempotent on email) so memberships can reference
        // any of them in pass 2 regardless of declaration order.
        $usersByEmail = [];
        foreach ($declarations as $declaration) {
            $email = (string) $declaration['email'];

            $user = $userClass::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $declaration['name'] ?? $email, 'password' => $password],
            );

            $usersByEmail[$email] = $user;
            $counts['users']++;
        }

        // Pass 2: accounts + memberships, via the real services.
        foreach ($declarations as $declaration) {
            $accountName = $declaration['account'] ?? null;
            if (! is_string($accountName) || $accountName === '') {
                continue;
            }

            $owner = $usersByEmail[(string) $declaration['email']];

            // Idempotent: reuse an existing owned account with the same name.
            $account = $owner->ownedAccounts()->where('name', $accountName)->first();
            if ($account === null) {
                $account = $this->accounts->create($owner, $accountName);
                $counts['accounts']++;
            }

            /** @var list<array<string, mixed>> $members */
            $members = $declaration['members'] ?? [];
            foreach ($members as $member) {
                $memberUser = $usersByEmail[(string) ($member['email'] ?? '')] ?? null;
                if ($memberUser === null || $memberUser->belongsToAccount($account)) {
                    continue;
                }

                $this->accounts->attachUser($account, $memberUser, (string) ($member['role'] ?? SystemRole::MEMBER));
                $counts['memberships']++;
            }
        }

        return $counts;
    }
}
