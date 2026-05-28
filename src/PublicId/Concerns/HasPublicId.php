<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Concerns;

use Illuminate\Database\Eloquent\Builder;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\PublicId\PublicId;

/**
 * Apply this trait to Eloquent models that need a public_id.
 *
 * The trait:
 *  - Auto-generates public_id on creating (the trait owns the column;
 *    do NOT add 'public_id' to $fillable)
 *  - Overrides route-model binding to use public_id
 *  - Provides scopeWherePublicId and scopeWherePublicIdIn query scopes
 *  - Resolves the prefix via PrefixRegistry — either through an override
 *    of publicIdPrefix() on the model, or via the prefixes map in
 *    config/jamesgifford/auth.php
 *
 * The model's table must have a public_id column sized to PublicId::maxLength():
 *
 *   $table->string('public_id', PublicId::maxLength())->unique();
 *
 * Override publicIdPrefix() on the model to declare the prefix locally:
 *
 *   public function publicIdPrefix(): string { return 'usr'; }
 *
 * Or leave it to the trait's default and register the model in config:
 *
 *   'prefixes' => [App\Models\User::class => 'usr'],
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

    /**
     * Default prefix lookup via the registry. Override this method on the
     * model to declare the prefix inline instead of using the config map.
     */
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
