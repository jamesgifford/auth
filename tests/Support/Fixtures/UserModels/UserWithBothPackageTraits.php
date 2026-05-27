<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Support\Fixtures\UserModels;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Progravity\Auth\Concerns\HasAccounts;
use Progravity\Auth\PublicId\Concerns\HasPublicId;

class UserWithBothPackageTraits extends Authenticatable
{
    use HasAccounts;
    use HasFactory;
    use HasPublicId;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    public function publicIdPrefix(): string
    {
        return 'usr';
    }
}
