<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\PackageModels;

/**
 * Produces AccountRole records for tests. By default it creates custom
 * (non-system) roles with a random key; system roles in tests usually come
 * from the seeder rather than this factory.
 *
 * @extends Factory<AccountRole>
 */
class AccountRoleFactory extends Factory
{
    /**
     * Config-resolved so the factory produces the CONFIGURED role model.
     *
     * @return class-string<AccountRole>
     */
    public function modelName(): string
    {
        return PackageModels::accountRole();
    }

    public function definition(): array
    {
        $key = fake()->unique()->slug(2);

        return [
            'key' => $key,
            'name' => str($key)->headline()->toString(),
            'description' => fake()->sentence(),
            'system' => false,
            'sort_order' => fake()->numberBetween(10, 100),
        ];
    }

    public function system(): self
    {
        return $this->state(['system' => true]);
    }

    public function withKey(string $key): self
    {
        return $this->state(['key' => $key]);
    }
}
