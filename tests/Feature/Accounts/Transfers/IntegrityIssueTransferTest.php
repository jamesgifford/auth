<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Accounts\Transfers;

use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Transfers\IntegrityIssueType;

class IntegrityIssueTransferTest extends AccountsTestCase
{
    public function test_enum_cases(): void
    {
        $this->assertSame('no_owner_membership', IntegrityIssueType::NoOwnerMembership->value);
        $this->assertSame('multiple_owner_memberships', IntegrityIssueType::MultipleOwnerMemberships->value);
        $this->assertSame('owner_id_mismatch', IntegrityIssueType::OwnerIdMismatch->value);
    }
}
