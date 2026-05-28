<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts;

use Illuminate\Support\Facades\Event;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Events\AccountRestored;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Transfers\AccountTransfer;

class AccountServiceRestoreTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_restoring_clears_deleted_at(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $this->service->delete($account);
        $this->assertNotNull($account->fresh()->deleted_at);

        $this->service->restore($account);

        $this->assertNull($account->fresh()->deleted_at);
    }

    public function test_restored_account_queryable_normally(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $this->service->delete($account);
        $this->service->restore($account);

        $this->assertNotNull(Account::find($account->id));
    }

    public function test_memberships_unchanged_through_delete_and_restore(): void
    {
        ['account' => $account, 'membership' => $ownerMembership] = $this->createUserWithAccount();
        $member = User::factory()->create();
        $memberMembershipId = AccountUser::factory()->for($account)->for($member)->memberRole()->create()->id;

        $this->service->delete($account);
        $this->service->restore($account);

        $this->assertDatabaseHas('account_user', ['id' => $ownerMembership->id]);
        $this->assertDatabaseHas('account_user', ['id' => $memberMembershipId]);
    }

    public function test_account_restored_event_dispatched(): void
    {
        Event::fake([AccountRestored::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $this->service->delete($account);

        $this->service->restore($account);

        Event::assertDispatched(AccountRestored::class, 1);
    }

    public function test_event_carries_correct_transfer(): void
    {
        Event::fake([AccountRestored::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $this->service->delete($account);

        $this->service->restore($account);

        Event::assertDispatched(AccountRestored::class, function (AccountRestored $event) use ($account) {
            return $event->account instanceof AccountTransfer
                && $event->account->id === $account->id
                && $event->account->publicId === $account->public_id;
        });
    }

    public function test_restore_on_non_deleted_account_is_noop(): void
    {
        Event::fake([AccountRestored::class]);

        ['account' => $account] = $this->createUserWithAccount();
        $this->assertNull($account->deleted_at);

        // Should not throw, should not fire an event.
        $this->service->restore($account);

        $this->assertNull($account->fresh()->deleted_at);
        Event::assertNotDispatched(AccountRestored::class);
    }

    public function test_restore_does_not_re_point_current_account_id(): void
    {
        ['account' => $account] = $this->createUserWithAccount();
        $member = User::factory()->create();
        AccountUser::factory()->for($account)->for($member)->memberRole()->create();
        $member->switchToAccount($account);

        // delete() nulls current_account_id; restore() should NOT bring it back.
        $this->service->delete($account);
        $this->assertNull($member->fresh()->current_account_id);

        $this->service->restore($account);

        $this->assertNull($member->fresh()->current_account_id);
    }
}
