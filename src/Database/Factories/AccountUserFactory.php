<?php

declare(strict_types=1);

namespace Progravity\Auth\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Progravity\Auth\Models\AccountRole;
use Progravity\Auth\Models\AccountUser;
use Progravity\Auth\SystemRole;

/**
 * Produces AccountUser (membership) records for tests. Callers provide the
 * account, user, and role via ::for() / state helpers or an explicit array.
 *
 * The role-state helpers (ownerRole(), etc.) resolve the role id from the
 * account_roles table, so the seeder must have run before they are used.
 *
 * @extends Factory<AccountUser>
 */
class AccountUserFactory extends Factory
{
    protected $model = AccountUser::class;

    public function definition(): array
    {
        return [
            'joined_at' => now(),
            // account_id, user_id, account_role_id all required without
            // defaults; callers provide via ::for() or an explicit array.
        ];
    }

    public function ownerRole(): self
    {
        return $this->withRole(SystemRole::OWNER);
    }

    public function adminRole(): self
    {
        return $this->withRole(SystemRole::ADMIN);
    }

    public function memberRole(): self
    {
        return $this->withRole(SystemRole::MEMBER);
    }

    public function viewerRole(): self
    {
        return $this->withRole(SystemRole::VIEWER);
    }

    public function withRole(string $key): self
    {
        return $this->state(fn (): array => [
            'account_role_id' => AccountRole::findByKey($key)?->id,
        ]);
    }
}
