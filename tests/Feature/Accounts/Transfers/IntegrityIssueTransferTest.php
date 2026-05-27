<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts\Transfers;

use Error;
use Progravity\Auth\Tests\Feature\Accounts\AccountsTestCase;
use Progravity\Auth\Transfers\IntegrityIssueTransfer;
use Progravity\Auth\Transfers\IntegrityIssueType;

class IntegrityIssueTransferTest extends AccountsTestCase
{
    public function test_construction_with_all_properties(): void
    {
        $transfer = new IntegrityIssueTransfer(
            accountId: 42,
            accountPublicId: 'acc_abc',
            accountName: 'Acme',
            type: IntegrityIssueType::NoOwnerMembership,
            description: 'No owner.',
            metadata: ['owner_id' => 7],
        );

        $this->assertSame(42, $transfer->accountId);
        $this->assertSame('acc_abc', $transfer->accountPublicId);
        $this->assertSame('Acme', $transfer->accountName);
        $this->assertSame(IntegrityIssueType::NoOwnerMembership, $transfer->type);
        $this->assertSame('No owner.', $transfer->description);
        $this->assertSame(['owner_id' => 7], $transfer->metadata);
    }

    public function test_transfer_is_readonly(): void
    {
        $transfer = new IntegrityIssueTransfer(
            accountId: 1,
            accountPublicId: 'acc_x',
            accountName: 'X',
            type: IntegrityIssueType::OwnerIdMismatch,
            description: '',
            metadata: [],
        );

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line — testing readonly enforcement.
        $transfer->accountName = 'Mutated';
    }

    public function test_type_is_enum_instance(): void
    {
        $transfer = new IntegrityIssueTransfer(
            accountId: 1,
            accountPublicId: 'acc_x',
            accountName: 'X',
            type: IntegrityIssueType::MultipleOwnerMemberships,
            description: '',
            metadata: [],
        );

        $this->assertSame('multiple_owner_memberships', $transfer->type->value);
    }

    public function test_metadata_is_array(): void
    {
        $transfer = new IntegrityIssueTransfer(
            accountId: 1,
            accountPublicId: 'acc_x',
            accountName: 'X',
            type: IntegrityIssueType::MultipleOwnerMemberships,
            description: '',
            metadata: ['a' => 1, 'b' => [2, 3]],
        );

        $this->assertIsArray($transfer->metadata);
        $this->assertSame([2, 3], $transfer->metadata['b']);
    }

    public function test_enum_cases(): void
    {
        $this->assertSame('no_owner_membership', IntegrityIssueType::NoOwnerMembership->value);
        $this->assertSame('multiple_owner_memberships', IntegrityIssueType::MultipleOwnerMemberships->value);
        $this->assertSame('owner_id_mismatch', IntegrityIssueType::OwnerIdMismatch->value);
    }
}
