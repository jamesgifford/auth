<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\PackageModels;

/**
 * Produces Account records for tests. Data only — this factory does NOT
 * create the owner membership row or enforce any account invariants. That is
 * AccountService's responsibility. Tests that need a fully formed account
 * create the account, then the owner's AccountUser row, explicitly.
 *
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Config-resolved so Account::factory() (and any subclass inheriting this
     * factory) produces the CONFIGURED account model, not always the base.
     *
     * @return class-string<Account>
     */
    public function modelName(): string
    {
        return PackageModels::account();
    }

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Workspace',
            // owner_id is required but has no sensible default; callers must
            // provide it via ownedBy($user) or by passing owner_id in create().
        ];
    }

    public function ownedBy(Model $user): self
    {
        return $this->state(['owner_id' => $user->getKey()]);
    }
}
