<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures;

use Illuminate\Database\Eloquent\Model;

class FixtureModelNoTrait extends Model
{
    protected $table = 'fixture_models';
}
