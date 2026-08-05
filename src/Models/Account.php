<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use JamesGifford\Auth\Database\Factories\AccountFactory;
use JamesGifford\Auth\PackageModels;
use JamesGifford\Auth\PublicId\Concerns\HasPublicId;

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
 * may extend this class and point config('jamesgifford.auth.models.account')
 * at their subclass.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property int $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|null $owner
 * @property-read Collection<int, AccountUser> $memberships
 */
#[Fillable(['name', 'owner_id'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    public function publicIdPrefix(): string
    {
        return 'account';
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(PackageModels::user(), 'owner_id');
    }

    /**
     * @return BelongsToMany<Model, $this, AccountUser>
     */
    public function members(): BelongsToMany
    {
        // Pivot keys are pinned explicitly: with config-resolved classes,
        // Eloquent must never guess column names from a consumer subclass's
        // basename (OverrideAccount => override_account_id).
        return $this->belongsToMany(PackageModels::user(), 'account_user', 'account_id', 'user_id')
            ->using(PackageModels::accountUser())
            ->withPivot(['account_role_id', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<AccountUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(PackageModels::accountUser(), 'account_id');
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
