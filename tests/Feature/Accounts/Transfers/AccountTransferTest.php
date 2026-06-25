<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts\Transfers;

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

    public function test_transfer_is_readonly(): void
    {
        $owner = User::factory()->create();
        $account = Account::create(['name' => 'Acme', 'owner_id' => $owner->id]);
        $transfer = AccountTransfer::fromModel($account);

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line — testing readonly enforcement.
        $transfer->name = 'Mutated';
    }
}
