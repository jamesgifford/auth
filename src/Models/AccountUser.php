<?php

declare(strict_types=1);

namespace Progravity\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Progravity\Auth\Database\Factories\AccountUserFactory;
use Progravity\Auth\SystemRole;

/**
 * The explicit pivot for the Account ↔ User relationship.
 *
 * Each row records a single membership: which user belongs to which account,
 * under which role, and when they joined. Unlike a plain pivot this model has
 * its own autoincrement id, letting memberships be treated as first-class
 * records (direct querying, easier event handling).
 *
 * This model is data + relationships only. Consumers may extend it and point
 * config('progravity.auth.models.account_user') at their subclass.
 */
class AccountUser extends Pivot
{
    use HasFactory;

    public $incrementing = true;

    protected $table = 'account_user';

    protected $fillable = ['account_id', 'user_id', 'account_role_id', 'joined_at'];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('progravity.auth.models.user'), 'user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AccountRole::class, 'account_role_id');
    }

    public function isOwner(): bool
    {
        return $this->hasRole(SystemRole::OWNER);
    }

    public function hasRole(string $key): bool
    {
        return $this->role?->key === $key;
    }

    protected static function newFactory(): AccountUserFactory
    {
        return AccountUserFactory::new();
    }
}
