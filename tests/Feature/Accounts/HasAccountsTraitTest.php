<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Carbon;
use JamesGifford\Auth\Exceptions\NotAMemberException;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class HasAccountsTraitTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    // ----- Relationship tests -----

    public function test_accounts_returns_empty_collection_for_user_with_no_memberships(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->accounts);
    }

    public function test_accounts_returns_the_account_when_a_membership_exists(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $user->refresh();

        $this->assertCount(1, $user->accounts);
        $this->assertSame($account->id, $user->accounts->first()->id);
    }

    public function test_accounts_returns_multiple_accounts_for_a_user_in_multiple_accounts(): void
    {
        ['user' => $user, 'account' => $a] = $this->createUserWithAccount();

        $otherOwner = User::factory()->create();
        $b = Account::factory()->ownedBy($otherOwner)->create();
        AccountUser::factory()->for($b)->for($user)->memberRole()->create();

        $c = Account::factory()->ownedBy($otherOwner)->create();
        AccountUser::factory()->for($c)->for($user)->viewerRole()->create();

        $user->refresh();

        $this->assertCount(3, $user->accounts);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id, $c->id],
            $user->accounts->pluck('id')->all()
        );
    }

    public function test_accounts_includes_pivot_data(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $user->refresh();
        $pivot = $user->accounts->first()->pivot;

        $this->assertInstanceOf(AccountUser::class, $pivot);
        $this->assertSame(AccountRole::findByKey('owner')->id, $pivot->account_role_id);
        $this->assertNotNull($pivot->joined_at);
    }

    public function test_memberships_returns_account_user_records_directly(): void
    {
        ['user' => $user, 'membership' => $membership] = $this->createUserWithAccount();

        $user->refresh();

        $this->assertCount(1, $user->memberships);
        $this->assertInstanceOf(AccountUser::class, $user->memberships->first());
        $this->assertSame($membership->id, $user->memberships->first()->id);
    }

    public function test_current_account_returns_null_when_current_account_id_is_null(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->current_account_id);
        $this->assertNull($user->currentAccount);
    }

    public function test_current_account_returns_the_account_when_set(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $user->switchToAccount($account);
        $user->refresh();

        $this->assertInstanceOf(Account::class, $user->currentAccount);
        $this->assertSame($account->id, $user->currentAccount->id);
    }

    public function test_current_account_returns_null_after_referenced_account_is_hard_deleted(): void
    {
        // The owner FK from accounts.owner_id would block this user being
        // deleted, but here we delete the account itself — its current_account_id
        // FK uses nullOnDelete, so the user's pointer should clear.
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();
        $user->switchToAccount($account);

        $account->forceDelete();
        $user->refresh();

        $this->assertNull($user->current_account_id);
        $this->assertNull($user->currentAccount);
    }

    public function test_owned_accounts_returns_accounts_where_user_is_owner(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $user->refresh();

        $this->assertCount(1, $user->ownedAccounts);
        $this->assertSame($account->id, $user->ownedAccounts->first()->id);
    }

    public function test_owned_accounts_excludes_accounts_user_belongs_to_but_does_not_own(): void
    {
        $owner = User::factory()->create();
        $accountOwned = Account::factory()->ownedBy($owner)->create();
        AccountUser::factory()->for($accountOwned)->for($owner)->ownerRole()->create();

        $member = User::factory()->create();
        AccountUser::factory()->for($accountOwned)->for($member)->memberRole()->create();

        $this->assertCount(0, $member->ownedAccounts);
    }

    // ----- Membership helper tests -----

    public function test_belongs_to_account_returns_true_for_a_member(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $this->assertTrue($user->belongsToAccount($account));
    }

    public function test_belongs_to_account_returns_false_for_a_non_member(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->belongsToAccount($account));
    }

    public function test_membership_in_returns_the_account_user_record_for_a_member(): void
    {
        ['user' => $user, 'account' => $account, 'membership' => $membership] = $this->createUserWithAccount();

        $found = $user->membershipIn($account);

        $this->assertInstanceOf(AccountUser::class, $found);
        $this->assertSame($membership->id, $found->id);
    }

    public function test_membership_in_returns_null_for_a_non_member(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->assertNull($stranger->membershipIn($account));
    }

    public function test_role_in_returns_the_account_role_for_a_member(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $role = $user->roleIn($account);

        $this->assertInstanceOf(AccountRole::class, $role);
        $this->assertSame('owner', $role->key);
    }

    public function test_role_in_returns_null_for_a_non_member(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->assertNull($stranger->roleIn($account));
    }

    public function test_role_in_returns_correct_role_when_user_has_different_roles_in_different_accounts(): void
    {
        ['user' => $user, 'account' => $accountA] = $this->createUserWithAccount();

        $otherOwner = User::factory()->create();
        $accountB = Account::factory()->ownedBy($otherOwner)->create();
        AccountUser::factory()->for($accountB)->for($user)->memberRole()->create();

        $this->assertSame('owner', $user->roleIn($accountA)->key);
        $this->assertSame('member', $user->roleIn($accountB)->key);
    }

    // ----- Role-checking tests -----

    public function test_has_role_owner_returns_true_for_owner(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $this->assertTrue($user->hasRole($account, 'owner'));
    }

    public function test_has_role_admin_returns_true_for_admin(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'admin');

        $this->assertTrue($user->hasRole($account, 'admin'));
    }

    public function test_has_role_owner_returns_false_when_user_is_admin(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'admin');

        $this->assertFalse($user->hasRole($account, 'owner'));
    }

    public function test_has_role_admin_returns_false_when_user_is_owner(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $this->assertFalse($user->hasRole($account, 'admin'));
    }

    public function test_has_role_returns_false_for_non_member_regardless_of_role_key(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->hasRole($account, 'owner'));
        $this->assertFalse($stranger->hasRole($account, 'admin'));
        $this->assertFalse($stranger->hasRole($account, 'member'));
        $this->assertFalse($stranger->hasRole($account, 'viewer'));
    }

    public function test_has_any_role_returns_true_when_users_role_matches_any_in_list(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'member');

        $this->assertTrue($user->hasAnyRole($account, ['member', 'admin']));
        $this->assertTrue($user->hasAnyRole($account, ['admin', 'member', 'viewer']));
    }

    public function test_has_any_role_returns_false_when_users_role_matches_none(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'viewer');

        $this->assertFalse($user->hasAnyRole($account, ['owner', 'admin', 'member']));
    }

    public function test_has_any_role_returns_false_for_non_member(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->hasAnyRole($account, ['owner', 'admin', 'member', 'viewer']));
    }

    public function test_is_owner_of_returns_true_for_owner(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $this->assertTrue($user->isOwnerOf($account));
    }

    public function test_is_owner_of_returns_false_for_admin(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'admin');

        $this->assertFalse($user->isOwnerOf($account));
    }

    public function test_is_owner_of_returns_false_for_member(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'member');

        $this->assertFalse($user->isOwnerOf($account));
    }

    public function test_is_owner_of_returns_false_for_viewer(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'viewer');

        $this->assertFalse($user->isOwnerOf($account));
    }

    public function test_is_owner_of_returns_false_for_non_member(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->isOwnerOf($account));
    }

    public function test_is_admin_of_returns_true_for_owner(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $this->assertTrue($user->isAdminOf($account));
    }

    public function test_is_admin_of_returns_true_for_admin(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'admin');

        $this->assertTrue($user->isAdminOf($account));
    }

    public function test_is_admin_of_returns_false_for_member(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'member');

        $this->assertFalse($user->isAdminOf($account));
    }

    public function test_is_admin_of_returns_false_for_viewer(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount(role: 'viewer');

        $this->assertFalse($user->isAdminOf($account));
    }

    public function test_is_admin_of_returns_false_for_non_member(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->isAdminOf($account));
    }

    // ----- Floating-user tests -----

    public function test_has_any_account_returns_false_for_user_with_no_memberships(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasAnyAccount());
    }

    public function test_has_any_account_returns_true_after_membership_is_created(): void
    {
        ['user' => $user] = $this->createUserWithAccount();

        $this->assertTrue($user->hasAnyAccount());
    }

    public function test_is_floating_returns_true_for_user_with_no_memberships(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->isFloating());
    }

    public function test_is_floating_returns_false_after_membership_is_created(): void
    {
        ['user' => $user] = $this->createUserWithAccount();

        $this->assertFalse($user->isFloating());
    }

    public function test_is_floating_returns_true_after_users_only_membership_is_deleted(): void
    {
        // Owner FK on accounts.owner_id restricts deleting the user; deleting
        // a non-owner member here so we can exercise the membership removal
        // without tripping the owner FK.
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $membership = AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $this->assertFalse($member->isFloating());

        $membership->delete();

        $this->assertTrue($member->isFloating());
    }

    // ----- Account switching tests -----

    public function test_switch_to_account_updates_current_account_id_for_member(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $user->switchToAccount($account);

        $this->assertSame($account->id, $user->current_account_id);
    }

    public function test_switch_to_account_persists_the_change(): void
    {
        ['user' => $user, 'account' => $account] = $this->createUserWithAccount();

        $user->switchToAccount($account);

        $reloaded = User::find($user->id);
        $this->assertSame($account->id, $reloaded->current_account_id);
    }

    public function test_switch_to_account_throws_when_user_is_not_a_member(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $this->expectException(NotAMemberException::class);

        $stranger->switchToAccount($account);
    }

    public function test_switch_to_account_throws_with_both_public_ids_in_message(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        try {
            $stranger->switchToAccount($account);
            $this->fail('Expected NotAMemberException was not thrown.');
        } catch (NotAMemberException $e) {
            $this->assertStringContainsString($stranger->public_id, $e->getMessage());
            $this->assertStringContainsString($account->public_id, $e->getMessage());
        }
    }

    public function test_switch_to_account_does_not_lose_previous_memberships(): void
    {
        ['user' => $user, 'account' => $accountA] = $this->createUserWithAccount();

        $otherOwner = User::factory()->create();
        $accountB = Account::factory()->ownedBy($otherOwner)->create();
        AccountUser::factory()->for($accountB)->for($user)->memberRole()->create();

        $user->switchToAccount($accountB);
        $user->refresh();

        $this->assertCount(2, $user->memberships);
        $this->assertSame($accountB->id, $user->current_account_id);
    }

    public function test_switch_to_account_can_alternate_between_member_accounts(): void
    {
        ['user' => $user, 'account' => $accountA] = $this->createUserWithAccount();

        $otherOwner = User::factory()->create();
        $accountB = Account::factory()->ownedBy($otherOwner)->create();
        AccountUser::factory()->for($accountB)->for($user)->memberRole()->create();

        $user->switchToAccount($accountA);
        $this->assertSame($accountA->id, $user->fresh()->current_account_id);

        $user->switchToAccount($accountB);
        $this->assertSame($accountB->id, $user->fresh()->current_account_id);

        $user->switchToAccount($accountA);
        $this->assertSame($accountA->id, $user->fresh()->current_account_id);
    }

    // ----- Query scope tests -----

    public function test_floating_scope_returns_users_with_no_memberships(): void
    {
        $floating1 = User::factory()->create();
        $floating2 = User::factory()->create();
        $this->createUserWithAccount();

        $found = User::floating()->pluck('id')->all();

        $this->assertContains($floating1->id, $found);
        $this->assertContains($floating2->id, $found);
    }

    public function test_floating_scope_excludes_users_with_at_least_one_membership(): void
    {
        ['user' => $member] = $this->createUserWithAccount();

        $found = User::floating()->pluck('id')->all();

        $this->assertNotContains($member->id, $found);
    }

    public function test_with_account_scope_returns_members_of_account(): void
    {
        ['user' => $owner, 'account' => $account] = $this->createUserWithAccount();

        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();

        $found = User::withAccount($account)->pluck('id')->all();

        $this->assertContains($owner->id, $found);
        $this->assertContains($member->id, $found);
    }

    public function test_with_account_scope_excludes_non_members(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $stranger = User::factory()->create();

        $found = User::withAccount($account)->pluck('id')->all();

        $this->assertNotContains($stranger->id, $found);
    }

    public function test_with_account_scope_count_matches_membership_count(): void
    {
        ['account' => $account] = $this->createUserWithAccount();

        for ($i = 0; $i < 3; $i++) {
            $user = User::factory()->create();
            AccountUser::factory()->for($account)->for($user)->memberRole()->create();
        }

        // 1 owner + 3 members
        $this->assertSame(4, User::withAccount($account)->count());
        $this->assertSame(4, $account->memberships()->count());
    }

    // ----- Multi-account scenarios -----

    public function test_user_in_three_accounts_has_accounts_count_three(): void
    {
        ['user' => $user] = $this->createUserWithAccount();

        $otherOwner = User::factory()->create();
        for ($i = 0; $i < 2; $i++) {
            $a = Account::factory()->ownedBy($otherOwner)->create();
            AccountUser::factory()->for($a)->for($user)->memberRole()->create();
        }

        $this->assertSame(3, $user->accounts()->count());
    }

    public function test_role_in_returns_correct_role_per_account(): void
    {
        ['user' => $user, 'account' => $accountA] = $this->createUserWithAccount();

        $otherOwner = User::factory()->create();
        $accountB = Account::factory()->ownedBy($otherOwner)->create();
        AccountUser::factory()->for($accountB)->for($user)->adminRole()->create();

        $accountC = Account::factory()->ownedBy($otherOwner)->create();
        AccountUser::factory()->for($accountC)->for($user)->viewerRole()->create();

        $this->assertSame('owner', $user->roleIn($accountA)->key);
        $this->assertSame('admin', $user->roleIn($accountB)->key);
        $this->assertSame('viewer', $user->roleIn($accountC)->key);
    }

    public function test_owner_of_a_only_when_member_of_b(): void
    {
        ['user' => $user, 'account' => $accountA] = $this->createUserWithAccount();

        $otherOwner = User::factory()->create();
        $accountB = Account::factory()->ownedBy($otherOwner)->create();
        AccountUser::factory()->for($accountB)->for($user)->memberRole()->create();

        $this->assertTrue($user->isOwnerOf($accountA));
        $this->assertFalse($user->isOwnerOf($accountB));
    }

    public function test_current_account_points_at_one_of_multiple(): void
    {
        ['user' => $user] = $this->createUserWithAccount();

        $otherOwner = User::factory()->create();
        $accountB = Account::factory()->ownedBy($otherOwner)->create();
        AccountUser::factory()->for($accountB)->for($user)->memberRole()->create();

        $user->switchToAccount($accountB);
        $user->refresh();

        $this->assertSame($accountB->id, $user->currentAccount->id);
    }

    // Ensure timestamp parsing on the pivot does not surprise; references
    // Carbon to make the import meaningful when re-reading the file.
    public function test_pivot_joined_at_is_carbon_instance(): void
    {
        ['user' => $user] = $this->createUserWithAccount();
        $user->refresh();

        $pivot = $user->accounts->first()->pivot;

        $this->assertInstanceOf(Carbon::class, $pivot->joined_at);
    }
}
