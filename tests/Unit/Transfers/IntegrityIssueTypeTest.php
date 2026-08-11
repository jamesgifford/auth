<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\Transfers;

use JamesGifford\Auth\Transfers\IntegrityIssueType;
use PHPUnit\Framework\TestCase;

class IntegrityIssueTypeTest extends TestCase
{
    /**
     * Pins the backed values. They are what a consumer serializing an
     * IntegrityIssueTransfer sees, so renaming one is a breaking change to the
     * package's output — not merely an internal rename.
     */
    public function test_enum_cases(): void
    {
        $this->assertSame('no_owner_membership', IntegrityIssueType::NoOwnerMembership->value);
        $this->assertSame('multiple_owner_memberships', IntegrityIssueType::MultipleOwnerMemberships->value);
        $this->assertSame('owner_id_mismatch', IntegrityIssueType::OwnerIdMismatch->value);
    }
}
