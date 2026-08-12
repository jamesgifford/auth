<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\PublicId\Concerns\HasPublicId;

class FixtureModelWithUuid extends Model
{
    use HasPublicId;
    use HasUuids;

    protected $table = 'fixture_uuid_models';

    protected $fillable = ['name'];

    public function publicIdPrefix(): string
    {
        return 'fxu';
    }
}
