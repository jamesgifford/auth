<?php

declare(strict_types=1);

namespace Progravity\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Progravity\Auth\Database\Factories\AccountRoleFactory;
use Progravity\Auth\Database\Seeders\AccountRoleSeeder;
use Progravity\Auth\Exceptions\CannotDeleteSystemRoleException;

/**
 * A role that can be assigned to a member within an account.
 *
 * Roles are reference data. They are configured in
 * config('progravity.auth.roles') (the source of truth) and seeded into the
 * account_roles table via {@see AccountRoleSeeder}.
 *
 * System roles (system => true) ship with the package and cannot be deleted
 * via Eloquent — see {@see booted()}. Consumers may extend this class and
 * point config('progravity.auth.models.account_role') at their subclass.
 */
class AccountRole extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'name', 'description', 'system', 'sort_order'];

    protected $casts = [
        'system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(AccountUser::class, 'account_role_id');
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public static function findByKey(string $key): ?self
    {
        return static::query()->where('key', $key)->first();
    }

    protected static function booted(): void
    {
        static::deleting(function (AccountRole $role) {
            if ($role->isSystem()) {
                throw CannotDeleteSystemRoleException::forRole($role->key);
            }
        });
    }

    protected static function newFactory(): AccountRoleFactory
    {
        return AccountRoleFactory::new();
    }
}
