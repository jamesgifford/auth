<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures\Overrides;

use JamesGifford\Auth\Models\AccountRole;

/**
 * Bare subclass used to prove config('jamesgifford.auth.models.account_role')
 * overrides are honored everywhere the package resolves the role model.
 */
final class OverrideAccountRole extends AccountRole
{
    protected $table = 'account_roles';
}
