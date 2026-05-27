<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Support\Fixtures\UserModels;

use Illuminate\Database\Eloquent\Model;

/**
 * Extends Eloquent Model directly rather than Authenticatable. The installer
 * refuses to modify this kind of file because it can't reliably assume the
 * trait composition would be safe.
 */
class UserWithCustomBase extends Model
{
    protected $fillable = ['name', 'email'];
}
