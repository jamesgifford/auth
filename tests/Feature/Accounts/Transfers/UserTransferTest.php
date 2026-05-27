<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts\Transfers;

use Error;
use Progravity\Auth\Tests\Feature\Accounts\AccountsTestCase;
use Progravity\Auth\Tests\Support\Fixtures\User;
use Progravity\Auth\Transfers\UserTransfer;

class UserTransferTest extends AccountsTestCase
{
    public function test_from_model_produces_transfer_with_correct_values(): void
    {
        $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.test']);

        $transfer = UserTransfer::fromModel($user);

        $this->assertSame($user->id, $transfer->id);
        $this->assertSame($user->public_id, $transfer->publicId);
        $this->assertSame('Ada', $transfer->name);
        $this->assertSame('ada@example.test', $transfer->email);
    }

    public function test_from_model_handles_missing_public_id(): void
    {
        $user = User::factory()->create();
        $user->setAttribute('public_id', null);

        $transfer = UserTransfer::fromModel($user);

        $this->assertNull($transfer->publicId);
    }

    public function test_transfer_is_readonly(): void
    {
        $user = User::factory()->create();
        $transfer = UserTransfer::fromModel($user);

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line — testing readonly enforcement.
        $transfer->name = 'Mutated';
    }

    public function test_direct_construction_via_named_args(): void
    {
        $transfer = new UserTransfer(
            id: 1,
            publicId: 'usr_xyz',
            name: 'Direct',
            email: 'd@example.test',
        );

        $this->assertSame(1, $transfer->id);
        $this->assertSame('usr_xyz', $transfer->publicId);
        $this->assertSame('Direct', $transfer->name);
        $this->assertSame('d@example.test', $transfer->email);
    }
}
