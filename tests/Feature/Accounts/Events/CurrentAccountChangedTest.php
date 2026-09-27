<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Events\CurrentAccountChanged;
use JamesGifford\Auth\Exceptions\NotAMemberException;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\SystemRole;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use RuntimeException;

/**
 * CurrentAccountChanged fires whenever a user's current account actually
 * changes through the package — and only then — so apps can react (e.g. lock
 * account-scoped state) without hooking the user model's save. Also covers
 * the in-memory side of the same change: the cached currentAccount relation
 * must follow the new value in the same request.
 */
class CurrentAccountChangedTest extends AccountsTestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->service = $this->app->make(AccountService::class);
    }

    public function test_switching_accounts_reports_the_user_and_both_accounts(): void
    {
        $user = User::factory()->create();
        $first = $this->service->create($user);
        $second = $this->service->create($user);
        $user->switchToAccount($first);

        Event::fake([CurrentAccountChanged::class]);
        $user->switchToAccount($second);

        Event::assertDispatchedTimes(CurrentAccountChanged::class, 1);
        Event::assertDispatched(CurrentAccountChanged::class, fn (CurrentAccountChanged $event): bool => $event->user->id === $user->id
            && $event->user->publicId === $user->public_id
            && $event->previousAccount?->id === $first->id
            && $event->newAccount?->id === $second->id
            && $event->newAccount->publicId === $second->public_id);
    }

    public function test_a_first_switch_reports_no_previous_account(): void
    {
        $user = User::factory()->create();
        $account = $this->service->create($user);

        Event::fake([CurrentAccountChanged::class]);
        $user->switchToAccount($account);

        Event::assertDispatched(CurrentAccountChanged::class, fn (CurrentAccountChanged $event): bool => $event->previousAccount === null
            && $event->newAccount?->id === $account->id);
    }

    public function test_switching_to_the_account_that_is_already_current_dispatches_nothing(): void
    {
        $user = User::factory()->create();
        $account = $this->service->create($user);
        $user->switchToAccount($account);

        Event::fake([CurrentAccountChanged::class]);
        $user->switchToAccount($account);
        $user->fresh()->switchToAccount($account);

        Event::assertNotDispatched(CurrentAccountChanged::class);
    }

    public function test_a_rejected_switch_dispatches_nothing(): void
    {
        $user = User::factory()->create();
        $foreign = $this->service->create(User::factory()->create());

        Event::fake([CurrentAccountChanged::class]);

        try {
            $user->switchToAccount($foreign);
            $this->fail('Expected the non-member switch to be rejected.');
        } catch (NotAMemberException) {
            // expected
        }

        Event::assertNotDispatched(CurrentAccountChanged::class);
    }

    public function test_being_removed_from_the_current_account_reports_the_change_to_none(): void
    {
        $owner = User::factory()->create();
        $account = $this->service->create($owner);
        $member = User::factory()->create();
        $this->service->attachUser($account, $member, SystemRole::MEMBER);
        $member->switchToAccount($account);

        Event::fake([CurrentAccountChanged::class]);
        $this->service->detachUser($account, $member);

        Event::assertDispatchedTimes(CurrentAccountChanged::class, 1);
        Event::assertDispatched(CurrentAccountChanged::class, fn (CurrentAccountChanged $event): bool => $event->user->id === $member->id
            && $event->previousAccount?->id === $account->id
            && $event->newAccount === null);
        $this->assertNull($member->fresh()->current_account_id);
    }

    public function test_being_removed_from_another_account_dispatches_nothing(): void
    {
        $owner = User::factory()->create();
        $other = $this->service->create($owner);
        $member = User::factory()->create();
        $own = $this->service->create($member);
        $this->service->attachUser($other, $member, SystemRole::MEMBER);
        $member->switchToAccount($own);

        Event::fake([CurrentAccountChanged::class]);
        $this->service->detachUser($other, $member);

        Event::assertNotDispatched(CurrentAccountChanged::class);
        $this->assertSame($own->id, $member->fresh()->current_account_id);
    }

    public function test_deleting_an_account_reports_the_change_for_each_user_it_was_current_for(): void
    {
        [$account, $holders, $bystander] = $this->accountWithTwoHoldersAndABystander();

        Event::fake([CurrentAccountChanged::class]);
        $this->service->delete($account);

        $this->assertReportedClearedFor($account, $holders, $bystander);
    }

    public function test_force_deleting_an_account_reports_the_change_for_each_user_it_was_current_for(): void
    {
        [$account, $holders, $bystander] = $this->accountWithTwoHoldersAndABystander();

        Event::fake([CurrentAccountChanged::class]);
        $this->service->forceDelete($account);

        $this->assertReportedClearedFor($account, $holders, $bystander);
    }

    public function test_the_event_does_not_fire_when_the_surrounding_transaction_rolls_back(): void
    {
        $user = User::factory()->create();
        $first = $this->service->create($user);
        $second = $this->service->create($user);
        $user->switchToAccount($first);

        Event::fake([CurrentAccountChanged::class]);

        try {
            DB::transaction(function () use ($user, $second): void {
                $user->switchToAccount($second);

                throw new RuntimeException('Outer rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        Event::assertNotDispatched(CurrentAccountChanged::class);
        $this->assertSame($first->id, $user->fresh()->current_account_id);
    }

    public function test_current_account_relation_returns_the_new_account_immediately_after_a_switch(): void
    {
        $user = User::factory()->create();
        $first = $this->service->create($user);
        $second = $this->service->create($user);
        $user->switchToAccount($first);

        // Load (and cache) the relation, exactly as account scoping would.
        $this->assertTrue($user->currentAccount->is($first));

        $user->switchToAccount($second);

        $this->assertTrue($user->currentAccount->is($second));
        $this->assertSame($second->id, $user->current_account_id);
    }

    public function test_current_account_relation_is_cleared_when_removed_from_the_current_account(): void
    {
        $owner = User::factory()->create();
        $account = $this->service->create($owner);
        $member = User::factory()->create();
        $this->service->attachUser($account, $member, SystemRole::MEMBER);
        $member->switchToAccount($account);
        $this->assertTrue($member->currentAccount->is($account));

        $this->service->detachUser($account, $member);

        $this->assertNull($member->currentAccount);
        $this->assertNull($member->current_account_id);
    }

    /**
     * @return array{0: Account, 1: list<User>, 2: User}
     */
    private function accountWithTwoHoldersAndABystander(): array
    {
        $owner = User::factory()->create();
        $account = $this->service->create($owner);
        $member = User::factory()->create();
        $this->service->attachUser($account, $member, SystemRole::MEMBER);
        $owner->switchToAccount($account);
        $member->switchToAccount($account);

        // A member of the same account whose current account is elsewhere.
        $bystander = User::factory()->create();
        $this->service->attachUser($account, $bystander, SystemRole::MEMBER);
        $bystander->switchToAccount($this->service->create($bystander));

        return [$account, [$owner, $member], $bystander];
    }

    /**
     * @param  list<User>  $holders
     */
    private function assertReportedClearedFor(Account $account, array $holders, User $bystander): void
    {
        Event::assertDispatchedTimes(CurrentAccountChanged::class, count($holders));

        foreach ($holders as $holder) {
            Event::assertDispatched(CurrentAccountChanged::class, fn (CurrentAccountChanged $event): bool => $event->user->id === $holder->id
                && $event->previousAccount?->id === $account->id
                && $event->newAccount === null);
            $this->assertNull($holder->fresh()->current_account_id);
        }

        Event::assertNotDispatched(CurrentAccountChanged::class, fn (CurrentAccountChanged $event): bool => $event->user->id === $bystander->id);
    }
}
