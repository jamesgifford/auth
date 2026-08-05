<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support\Fixtures\Overrides;

use JamesGifford\Auth\Models\Account;

/**
 * Bare subclass used to prove config('jamesgifford.auth.models.account')
 * overrides are honored everywhere the package resolves the account model.
 */
final class OverrideAccount extends Account
{
    protected $table = 'accounts';
}
