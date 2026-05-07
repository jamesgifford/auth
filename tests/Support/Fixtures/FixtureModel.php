<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Support\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Progravity\Auth\PublicId\Concerns\HasPublicId;

class FixtureModel extends Model
{
    use HasPublicId;

    protected $table = 'fixture_models';

    protected $fillable = ['name'];

    public function publicIdPrefix(): string
    {
        return 'fix';
    }
}
