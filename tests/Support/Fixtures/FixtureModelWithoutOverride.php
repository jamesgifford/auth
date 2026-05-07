<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Support\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Progravity\Auth\PublicId\Concerns\HasPublicId;

class FixtureModelWithoutOverride extends Model
{
    use HasPublicId;

    protected $table = 'fixture_models';
}
