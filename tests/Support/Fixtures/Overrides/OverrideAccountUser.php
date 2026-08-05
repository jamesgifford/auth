<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures\Overrides;

use JamesGifford\Auth\Models\AccountUser;

/**
 * Bare subclass used to prove config('jamesgifford.auth.models.account_user')
 * overrides are honored everywhere the package resolves the pivot model.
 */
final class OverrideAccountUser extends AccountUser
{
    protected $table = 'account_user';
}
