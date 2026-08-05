<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\SystemRole;
use JamesGifford\Auth\Tests\Support\Fixtures\Overrides\OverrideAccount;
use JamesGifford\Auth\Tests\Support\Fixtures\Overrides\OverrideAccountRole;
use JamesGifford\Auth\Tests\Support\Fixtures\Overrides\OverrideAccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

/**
 * Proves the documented contract of config('jamesgifford.auth.models.*'):
 * pointing a models key at a subclass makes the PACKAGE use that subclass
 * everywhere — service returns, trait relationships, pivot instances, and
 * factories. These are exactly the paths where a half-wired override would
 * silently fall back to the base class.
 */
final class ConfiguredModelOverridesTest extends AccountsTestCase
{
    public function test_account_service_creates_and_returns_configured_subclasses(): void
    {
        $this->seedRoles();
        $owner = User::factory()->create();

        $account = $this->service()->create($owner, 'Acme Inc');

        $this->assertInstanceOf(OverrideAccount::class, $account);
        $this->assertInstanceOf(OverrideAccountUser::class, $account->memberships()->first());

        $member = User::factory()->create();
        $membership = $this->service()->attachUser($account, $member, SystemRole::MEMBER);

        $this->assertInstanceOf(OverrideAccountUser::class, $membership);
        $this->assertInstanceOf(OverrideAccountRole::class, $membership->role);
    }

    public function test_has_accounts_relationships_return_configured_subclasses(): void
    {
        $this->seedRoles();
        $owner = User::factory()->create();
        $account = $this->service()->create($owner, 'Acme Inc');

        $member = User::factory()->create();
        $this->service()->attachUser($account, $member, SystemRole::MEMBER);
        $member->switchToAccount($account);

        $viaAccounts = $member->accounts()->first();
        $this->assertInstanceOf(OverrideAccount::class, $viaAccounts);
        $this->assertInstanceOf(OverrideAccountUser::class, $viaAccounts->pivot);

        $this->assertInstanceOf(OverrideAccountUser::class, $member->memberships()->first());
        $this->assertInstanceOf(OverrideAccount::class, $member->currentAccount()->first());
        $this->assertInstanceOf(OverrideAccount::class, $owner->ownedAccounts()->first());
        $this->assertInstanceOf(OverrideAccountRole::class, $member->roleIn($account));
    }

    public function test_account_relationships_return_configured_subclasses(): void
    {
        $this->seedRoles();
        $owner = User::factory()->create();
        $account = $this->service()->create($owner, 'Acme Inc');

        $this->assertInstanceOf(OverrideAccountUser::class, $account->memberships()->first());
        $this->assertInstanceOf(OverrideAccountUser::class, $account->ownerMembership());
        $this->assertInstanceOf(OverrideAccountUser::class, $account->members()->first()->pivot);

        $role = $account->ownerMembership()->role;
        $this->assertInstanceOf(OverrideAccountRole::class, $role);
        $this->assertInstanceOf(OverrideAccountUser::class, $role->memberships()->first());
    }

    public function test_factories_produce_configured_subclasses(): void
    {
        $this->seedRoles();
        $owner = User::factory()->create();

        $account = Account::factory()->ownedBy($owner)->create();
        $this->assertInstanceOf(OverrideAccount::class, $account);

        $role = AccountRole::factory()->create();
        $this->assertInstanceOf(OverrideAccountRole::class, $role);

        // Relationship names are given explicitly: for() would otherwise
        // guess them from the OVERRIDE class basenames (overrideAccount).
        $membership = AccountUser::factory()
            ->for($account, 'account')
            ->for($owner, 'user')
            ->state(['account_role_id' => AccountRole::findByKey(SystemRole::OWNER)->id])
            ->create();
        $this->assertInstanceOf(OverrideAccountUser::class, $membership);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('jamesgifford.auth.models.account', OverrideAccount::class);
        $app['config']->set('jamesgifford.auth.models.account_user', OverrideAccountUser::class);
        $app['config']->set('jamesgifford.auth.models.account_role', OverrideAccountRole::class);
    }

    private function service(): AccountService
    {
        return $this->app->make(AccountService::class);
    }
}
