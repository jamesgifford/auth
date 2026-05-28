<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts\Transfers;

use DateTimeImmutable;
use Error;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Transfers\MembershipTransfer;

class MembershipTransferTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_from_model_produces_transfer_with_correct_values(): void
    {
        ['user' => $user, 'account' => $account, 'membership' => $membership] = $this->createUserWithAccount();

        $transfer = MembershipTransfer::fromModel($membership);

        $this->assertSame($membership->id, $transfer->id);
        $this->assertSame($account->id, $transfer->accountId);
        $this->assertSame($user->id, $transfer->userId);
        $this->assertSame($membership->account_role_id, $transfer->accountRoleId);
    }

    public function test_joined_at_is_datetime_immutable(): void
    {
        ['membership' => $membership] = $this->createUserWithAccount();

        $transfer = MembershipTransfer::fromModel($membership);

        $this->assertInstanceOf(DateTimeImmutable::class, $transfer->joinedAt);
    }

    public function test_transfer_is_readonly(): void
    {
        ['membership' => $membership] = $this->createUserWithAccount();
        $transfer = MembershipTransfer::fromModel($membership);

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line — testing readonly enforcement.
        $transfer->accountId = 999;
    }

    public function test_direct_construction_via_named_args(): void
    {
        $now = new DateTimeImmutable;

        $transfer = new MembershipTransfer(
            id: 11,
            accountId: 1,
            userId: 2,
            accountRoleId: 3,
            joinedAt: $now,
        );

        $this->assertSame(11, $transfer->id);
        $this->assertSame(1, $transfer->accountId);
        $this->assertSame(2, $transfer->userId);
        $this->assertSame(3, $transfer->accountRoleId);
        $this->assertSame($now, $transfer->joinedAt);
    }
}
