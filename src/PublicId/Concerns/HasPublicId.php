<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Progravity\Auth\PublicId\PrefixRegistry;
use Progravity\Auth\PublicId\PublicId;

/**
 * Apply this trait to Eloquent models that need a public_id. The trait:
 *  - Auto-generates public_id on creating
 *  - Overrides route-model binding to use public_id
 *  - Provides scopeWherePublicId and scopeWherePublicIdIn query scopes
 *  - Resolves the prefix via PrefixRegistry (config or override publicIdPrefix())
 *
 * The model's table must have a public_id column sized to PublicId::maxLength().
 *
 * Example migration:
 *   $table->string('public_id', PublicId::maxLength())->unique();
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        app(PrefixRegistry::class)->register(static::class);

        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = PublicId::generate($model->publicIdPrefix());
            }
        });
    }

    public function publicIdPrefix(): string
    {
        return app(PrefixRegistry::class)->prefixFor(static::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->where('public_id', $publicId);
    }

    /**
     * @param  array<int, string>  $publicIds
     */
    public function scopeWherePublicIdIn(Builder $query, array $publicIds): Builder
    {
        return $query->whereIn('public_id', $publicIds);
    }
}
