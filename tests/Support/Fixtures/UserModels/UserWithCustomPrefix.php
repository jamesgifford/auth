<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Support\Fixtures\UserModels;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Has a publicIdPrefix() already, returning a custom value. The installer
 * must not overwrite this method — the prefix is a deliberate consumer
 * choice (e.g., 'mbr' for "member") that the package shouldn't override.
 */
class UserWithCustomPrefix extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    public function publicIdPrefix(): string
    {
        return 'mbr';
    }
}
