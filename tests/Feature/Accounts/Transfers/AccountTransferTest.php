<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts\Transfers;

use DateTimeImmutable;
use Error;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Transfers\AccountTransfer;

class AccountTransferTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_from_model_produces_transfer_with_correct_values(): void
    {
        $owner = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $owner->id]);

        $transfer = AccountTransfer::fromModel($account);

        $this->assertSame($account->id, $transfer->id);
        $this->assertSame($account->public_id, $transfer->publicId);
        $this->assertSame('Acme', $transfer->name);
        $this->assertSame($owner->id, $transfer->ownerId);
    }

    public function test_created_at_and_updated_at_are_datetime_immutable(): void
    {
        $owner = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $owner->id]);

        $transfer = AccountTransfer::fromModel($account);

        $this->assertInstanceOf(DateTimeImmutable::class, $transfer->createdAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $transfer->updatedAt);
    }

    public function test_transfer_is_readonly(): void
    {
        $owner = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $owner->id]);
        $transfer = AccountTransfer::fromModel($account);

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line — testing readonly enforcement.
        $transfer->name = 'Mutated';
    }

    public function test_direct_construction_via_named_args(): void
    {
        $now = new DateTimeImmutable;

        $transfer = new AccountTransfer(
            id: 42,
            publicId: 'acc_abc',
            name: 'Direct',
            ownerId: 7,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->assertSame(42, $transfer->id);
        $this->assertSame('acc_abc', $transfer->publicId);
        $this->assertSame('Direct', $transfer->name);
        $this->assertSame(7, $transfer->ownerId);
    }
}
