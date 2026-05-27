<?php

declare(strict_types=1);

namespace Progravity\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Progravity\Auth\Database\Factories\AccountFactory;
use Progravity\Auth\PublicId\Concerns\HasPublicId;

/**
 * An account (tenant) within the application.
 *
 * Every account has exactly one owner (a single mandatory ownership
 * invariant enforced by AccountService in a later phase) and zero or more
 * members tracked through the explicit {@see AccountUser} pivot. Accounts
 * are soft-deleted so membership rows can persist for undelete support.
 *
 * This model is data + relationships only. Behavior such as ownership
 * transfer or member attachment lives on AccountService, not here. Consumers
 * may extend this class and point config('progravity.auth.models.account')
 * at their subclass.
 */
class Account extends Model
{
    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = ['name', 'owner_id'];

    public function publicIdPrefix(): string
    {
        return 'acc';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(config('progravity.auth.models.user'), 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(config('progravity.auth.models.user'), 'account_user')
            ->using(AccountUser::class)
            ->withPivot(['account_role_id', 'joined_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AccountUser::class);
    }

    /**
     * The membership row for the current owner, or null when none exists
     * (the "no owner" failsafe — possible if invariants were bypassed).
     */
    public function ownerMembership(): ?AccountUser
    {
        return $this->memberships()->where('user_id', $this->owner_id)->first();
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }
}
