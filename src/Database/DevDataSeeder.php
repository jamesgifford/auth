<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Database;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Exceptions\DevDataSeedingNotAllowedException;
use JamesGifford\Auth\PublicId\Concerns\HasPublicId;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\SystemRole;
use Throwable;

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
        /** @var class-string<Model> $accountClass */
        $accountClass = config('jamesgifford.auth.models.account');
        /** @var class-string<Model> $accountUserClass */
        $accountUserClass = config('jamesgifford.auth.models.account_user');

        // When the in-process User model lacks an ACTIVE HasPublicId trait — e.g.
        // setup's install step just added it to the file, but the already-loaded
        // class is stale and PHP can't reload it — its public_id won't
        // auto-populate. Detect that so we can set it explicitly and seeding
        // works in the SAME process as the model edit, instead of crashing with
        // "Field 'public_id' doesn't have a default value".
        $userAutoGeneratesPublicId = in_array(HasPublicId::class, class_uses_recursive($userClass), true);

        /** @var list<array<string, mixed>> $declarations */
        $declarations = $config['users'] ?? [];

        $counts = ['users' => 0, 'accounts' => 0, 'memberships' => 0];

        // Pass 1: all users (idempotent on email) so memberships can reference
        // any of them in pass 2 regardless of declaration order.
        $usersByEmail = [];
        foreach ($declarations as $declaration) {
            $email = (string) $declaration['email'];

            $user = $userClass::query()->firstOrNew(['email' => $email]);
            $user->name = $declaration['name'] ?? $email;
            $user->password = $password;

            if (! $user->exists
                && empty($user->public_id)
                && ! $userAutoGeneratesPublicId
                && $user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), 'public_id')
            ) {
                $user->public_id = PublicId::generate($this->resolveUserPrefix($userClass));
            }

            $user->save();

            $usersByEmail[$email] = $user;
            $counts['users']++;
        }

        // Pass 2: accounts + memberships, via the real services. Idempotency is
        // checked through the package Account/AccountUser models (NOT the User
        // model's HasAccounts trait), so seeding never depends on the consumer
        // User model being loaded with its traits in this process.
        foreach ($declarations as $declaration) {
            $accountName = $declaration['account'] ?? null;
            if (! is_string($accountName) || $accountName === '') {
                continue;
            }

            $owner = $usersByEmail[(string) $declaration['email']];

            $account = $accountClass::query()
                ->where('owner_id', $owner->getKey())
                ->where('name', $accountName)
                ->first();
            if ($account === null) {
                $account = $this->accounts->create($owner, $accountName);
                $counts['accounts']++;
            }

            /** @var list<array<string, mixed>> $members */
            $members = $declaration['members'] ?? [];
            foreach ($members as $member) {
                $memberUser = $usersByEmail[(string) ($member['email'] ?? '')] ?? null;
                if ($memberUser === null) {
                    continue;
                }

                $alreadyMember = $accountUserClass::query()
                    ->where('account_id', $account->getKey())
                    ->where('user_id', $memberUser->getKey())
                    ->exists();
                if ($alreadyMember) {
                    continue;
                }

                $this->accounts->attachUser($account, $memberUser, (string) ($member['role'] ?? SystemRole::MEMBER));
                $counts['memberships']++;
            }
        }

        return $counts;
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
