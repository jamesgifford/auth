<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Support\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Progravity\Auth\Concerns\HasAccounts;
use Progravity\Auth\PublicId\Concerns\HasPublicId;

class User extends Authenticatable
{
    use HasAccounts;
    use HasFactory;
    use HasPublicId;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    public function publicIdPrefix(): string
    {
        return 'usr';
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
