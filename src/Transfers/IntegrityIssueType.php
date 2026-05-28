<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Transfers;

use JamesGifford\Auth\Accounts\Services\AccountIntegrityService;

/**
 * Categories of Owner-invariant violations detected by
 * {@see AccountIntegrityService}.
 *
 * The three cases cover the practical failure modes for the
 * "every account has exactly one Owner" invariant:
 *  - NoOwnerMembership: no member of the account holds the Owner role.
 *  - MultipleOwnerMemberships: more than one member holds the Owner role.
 *  - OwnerIdMismatch: accounts.owner_id and the Owner-role member disagree.
 */
enum IntegrityIssueType: string
{
    case NoOwnerMembership = 'no_owner_membership';
    case MultipleOwnerMemberships = 'multiple_owner_memberships';
    case OwnerIdMismatch = 'owner_id_mismatch';
}
