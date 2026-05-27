<?php

declare(strict_types=1);

namespace Progravity\Auth\Transfers;

use DateTimeImmutable;
use Progravity\Auth\Models\AccountUser;

/**
 * Immutable snapshot of an AccountUser (membership) row for use in events.
 *
 * Carries the foreign keys (account_id, user_id, account_role_id) rather
 * than nested transfers; events that need richer context for the related
 * entities pass dedicated AccountTransfer/UserTransfer/AccountRoleTransfer
 * properties alongside this one.
 */
final readonly class MembershipTransfer
{
    public function __construct(
        public int $id,
        public int $accountId,
        public int $userId,
        public int $accountRoleId,
        public DateTimeImmutable $joinedAt,
    ) {}

    public static function fromModel(AccountUser $membership): self
    {
        return new self(
            id: $membership->id,
            accountId: $membership->account_id,
            userId: $membership->user_id,
            accountRoleId: $membership->account_role_id,
            joinedAt: DateTimeImmutable::createFromInterface($membership->joined_at),
        );
    }
}
