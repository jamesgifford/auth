<?php

declare(strict_types=1);

namespace JamesGifford\Auth;

use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;

/**
 * Single source of truth for resolving the model classes the package operates
 * on, from config('jamesgifford.auth.models'). Every internal call site MUST
 * resolve model classes through this class rather than referencing the
 * concrete package models directly — that is what makes the documented
 * "point the models config at your own subclass" contract hold everywhere:
 * services, relationships, factories, seeding, and HTTP route binding alike.
 * ModelResolutionGuardTest enforces this for future code.
 *
 * Deliberately NOT cached and NOT container-bound: each call reads the config
 * repository directly, so a config change (tests, runtime) takes effect
 * immediately with no forgetInstance() bookkeeping, and behavior is identical
 * under config caching. The lookups are trivially cheap.
 *
 * Static-call chaining resolves through the configured class, so no query or
 * instance helpers are needed:
 *
 *   PackageModels::accountRole()::findByKey($key);
 *   PackageModels::accountUser()::query()->where(...);
 */
final class PackageModels
{
    private function __construct() {}

    /**
     * The consuming application's User model. The package has no user model
     * of its own, so there is no fallback — the merged package config always
     * defines the key.
     *
     * @return class-string<Model>
     */
    public static function user(): string
    {
        return config('jamesgifford.auth.models.user');
    }

    /**
     * @return class-string<Account>
     */
    public static function account(): string
    {
        return config('jamesgifford.auth.models.account', Account::class);
    }

    /**
     * @return class-string<AccountRole>
     */
    public static function accountRole(): string
    {
        return config('jamesgifford.auth.models.account_role', AccountRole::class);
    }

    /**
     * @return class-string<AccountUser>
     */
    public static function accountUser(): string
    {
        return config('jamesgifford.auth.models.account_user', AccountUser::class);
    }
}
