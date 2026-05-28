<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class HasAccountsIntegrationTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_user_gets_both_has_public_id_and_has_accounts_behaviors(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        // HasPublicId behavior
        $this->assertNotNull($user->public_id);
        $this->assertStringStartsWith('usr_', $user->public_id);

        // HasAccounts behavior
        $user->refresh();
        $this->assertCount(1, $user->accounts);
        $this->assertSame($account->id, $user->accounts->first()->id);
    }

    public function test_floating_user_has_null_current_account(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->isFloating());
        $this->assertNull($user->current_account_id);
        $this->assertNull($user->currentAccount);
    }

    /**
     * Deleting all of a user's memberships does NOT clear current_account_id.
     * That cleanup is a future concern (EnsureCurrentAccount middleware);
     * the trait deliberately leaves the pointer alone so removing a
     * membership stays a single-purpose operation.
     */
    public function test_orphaned_current_account_id_persists_after_membership_deletion(): void
    {
        // Use a non-owner member; the owner FK on accounts.owner_id would
        // restrict any operation that left an account ownerless.
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();

        $member = User::factory()->create();
        $membership = AccountUser::factory()->for($account)->for($member)->memberRole()->create();
        $member->switchToAccount($account);

        $this->assertSame($account->id, $member->current_account_id);
        $this->assertFalse($member->isFloating());

        // Hard-delete the membership without touching the account record.
        $membership->delete();
        $member->refresh();

        // The user is now floating but their pointer still references the
        // account — the trait does not proactively clean it up.
        $this->assertTrue($member->isFloating());
        $this->assertSame($account->id, $member->current_account_id);
        $this->assertInstanceOf(Account::class, $member->currentAccount);
        $this->assertSame($account->id, $member->currentAccount->id);
    }
}
