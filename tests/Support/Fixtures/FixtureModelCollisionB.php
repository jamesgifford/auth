<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\PublicId\Concerns\HasPublicId;

class FixtureModelCollisionB extends Model
{
    use HasPublicId;

    protected $table = 'fixture_models';

    public function publicIdPrefix(): string
    {
        return 'col';
    }
}
