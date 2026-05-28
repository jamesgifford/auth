<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts\Transfers;

use Error;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Transfers\AccountRoleTransfer;

class AccountRoleTransferTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_from_model_produces_transfer_with_correct_values(): void
    {
        $role = AccountRole::findByKey('owner');

        $transfer = AccountRoleTransfer::fromModel($role);

        $this->assertSame($role->id, $transfer->id);
        $this->assertSame('owner', $transfer->key);
        $this->assertSame('Owner', $transfer->name);
        $this->assertTrue($transfer->system);
    }

    public function test_from_model_carries_non_system_flag_for_custom_role(): void
    {
        $custom = AccountRole::create([
            'key' => 'auditor',
            'name' => 'Auditor',
            'system' => false,
            'sort_order' => 99,
        ]);

        $transfer = AccountRoleTransfer::fromModel($custom);

        $this->assertSame('auditor', $transfer->key);
        $this->assertFalse($transfer->system);
    }

    public function test_transfer_is_readonly(): void
    {
        $role = AccountRole::findByKey('admin');
        $transfer = AccountRoleTransfer::fromModel($role);

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line — testing readonly enforcement.
        $transfer->key = 'mutated';
    }

    public function test_direct_construction_via_named_args(): void
    {
        $transfer = new AccountRoleTransfer(
            id: 9,
            key: 'auditor',
            name: 'Auditor',
            system: false,
        );

        $this->assertSame(9, $transfer->id);
        $this->assertSame('auditor', $transfer->key);
        $this->assertSame('Auditor', $transfer->name);
        $this->assertFalse($transfer->system);
    }
}
