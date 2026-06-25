<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class AccountServiceForceDeleteTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_hard_deletes_the_account_row(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $id = $account->id;

        $this->service->forceDelete($account);

        $this->assertNull(Account::find($id));
        $this->assertNull(Account::withTrashed()->find($id));
    }

    public function test_account_user_rows_cascade_deleted(): void
    {
        ['account' => $account, 'membership' => $ownerMembership] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $memberMembershipId = AccountUser::factory()->for($account)->for($member)->memberRole()->create()->id;

        $this->service->forceDelete($account);

        $this->assertDatabaseMissing('account_user', ['id' => $ownerMembership->id]);
        $this->assertDatabaseMissing('account_user', ['id' => $memberMembershipId]);
    }

    public function test_current_account_id_nulled_by_fk(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();
        $member->switchToAccount($account);

        $this->service->forceDelete($account);

        $this->assertNull($member->fresh()->current_account_id);
    }
}
