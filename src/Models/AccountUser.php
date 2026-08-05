<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use JamesGifford\Auth\Database\Factories\AccountUserFactory;
use JamesGifford\Auth\PackageModels;
use JamesGifford\Auth\SystemRole;

/**
 * The explicit pivot for the Account ↔ User relationship.
 *
 * Each row records a single membership: which user belongs to which account,
 * under which role, and when they joined. Unlike a plain pivot this model has
 * its own autoincrement id, letting memberships be treated as first-class
 * records (direct querying, easier event handling).
 *
 * This model is data + relationships only. Consumers may extend it and point
 * config('jamesgifford.auth.models.account_user') at their subclass.
 *
 * @property int $id
 * @property int $account_id
 * @property int $user_id
 * @property int $account_role_id
 * @property Carbon|null $joined_at
 * @property-read Account|null $account
 * @property-read Model|null $user
 * @property-read AccountRole|null $role
 */
#[Fillable(['account_id', 'user_id', 'account_role_id', 'joined_at'])]
class AccountUser extends Pivot
{
    /** @use HasFactory<AccountUserFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'account_user';

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PackageModels::account(), 'account_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PackageModels::user(), 'user_id');
    }

    /**
     * @return BelongsTo<AccountRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(PackageModels::accountRole(), 'account_role_id');
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
