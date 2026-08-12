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
 *  - Auto-generates public_id via Eloquent's unique-id hook (runs before events,
 *    so it works even with Model::withoutEvents(); the trait owns the column,
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
 *   public function publicIdPrefix(): string { return 'inv'; }
 *
 * Or leave it to the trait's default and register the model in config:
 *
 *   'prefixes' => [App\Models\Invoice::class => 'inv'],
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
     * Opt into Eloquent's unique-id hook. Set from the trait initializer, the way
     * HasUniqueStringIds does it, rather than by redeclaring the inherited
     * $usesUniqueIds property — a trait property whose default differs from the
     * inherited one is a fatal conflict.
     */
    public function initializeHasPublicId(): void
    {
        $this->usesUniqueIds = true;
    }

    /**
     * Generate public_id.
     *
     * Model::performInsert() calls this BEFORE firing `creating`, and calls it
     * directly rather than through the event dispatcher — so it still runs under
     * Model::withoutEvents(): a DatabaseSeeder using WithoutModelEvents,
     * saveQuietly(), or any consumer-suppressed context. The `creating` hook in
     * bootHasPublicId() remains as a fallback for a model that turns
     * $usesUniqueIds off; it normally finds the value already set.
     *
     * parent::setUniqueIds() runs first so a model that ALSO uses HasUuids or
     * HasUlids still gets its key populated. This trait deliberately does not
     * declare uniqueIds()/newUniqueId(): both are defined by HasUniqueStringIds,
     * so declaring either would be a fatal trait-method collision on any model
     * combining the two — and newUniqueId() is per-model, not per-column, so it
     * could not serve a UUID key and a public_id at once.
     */
    public function setUniqueIds(): void
    {
        parent::setUniqueIds();

        if (empty($this->getAttribute('public_id'))) {
            $this->setAttribute('public_id', PublicId::generate($this->publicIdPrefix()));
        }
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->where('public_id', $publicId);
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<int, string>  $publicIds
     * @return Builder<static>
     */
    public function scopeWherePublicIdIn(Builder $query, array $publicIds): Builder
    {
        return $query->whereIn('public_id', $publicIds);
    }
}
