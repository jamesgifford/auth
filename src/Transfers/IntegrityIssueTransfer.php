<?php

declare(strict_types=1);

namespace Progravity\Auth\Transfers;

/**
 * One Owner-invariant violation detected by AccountIntegrityService.
 *
 * Unlike the other transfers in this namespace, this one has no fromModel()
 * factory — issues are synthesized from query results, not from a single
 * Eloquent record. The integrity service constructs these directly with the
 * relevant account identifiers and metadata.
 *
 * `metadata` carries issue-specific context, e.g. for MultipleOwnerMemberships
 * the list of user IDs holding Owner role, for OwnerIdMismatch the two
 * disagreeing values. Shape varies by {@see IntegrityIssueType}.
 */
final readonly class IntegrityIssueTransfer
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $accountId,
        public string $accountPublicId,
        public string $accountName,
        public IntegrityIssueType $type,
        public string $description,
        public array $metadata,
    ) {}
}
