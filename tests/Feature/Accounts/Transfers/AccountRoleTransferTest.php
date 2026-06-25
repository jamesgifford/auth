<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts\Transfers;

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
}
