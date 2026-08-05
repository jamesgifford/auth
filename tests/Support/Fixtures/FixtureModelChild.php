<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures;

/**
 * Subclass of FixtureModel that inherits its publicIdPrefix() ('fix') — the
 * shape a consumer's published App\Models subclass has. Used to prove a
 * same-prefix claim within one inheritance chain is a single logical
 * registration, not a collision.
 */
class FixtureModelChild extends FixtureModel
{
    protected $table = 'fixture_models';
}
