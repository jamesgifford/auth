<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts\Transfers;

use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Tests\Support\Fixtures\User;
use JamesGifford\Auth\Transfers\UserTransfer;

class UserTransferTest extends AccountsTestCase
{
    public function test_from_model_handles_missing_public_id(): void
    {
        $user = User::factory()->create();
        $user->setAttribute('public_id', null);

        $transfer = UserTransfer::fromModel($user);

        $this->assertNull($transfer->publicId);
    }
}
