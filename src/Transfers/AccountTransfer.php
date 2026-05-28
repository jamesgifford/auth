<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Transfers;

use DateTimeImmutable;
use JamesGifford\Auth\Models\Account;

/**
 * Immutable snapshot of Account state for use in events.
 *
 * Events carry transfers instead of live model references so listeners
 * receive a stable point-in-time view of the data: subsequent edits to the
 * model don't reach into queued jobs, and listeners can be serialized.
 */
final readonly class AccountTransfer
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $name,
        public int $ownerId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public static function fromModel(Account $account): self
    {
        return new self(
            id: $account->id,
            publicId: $account->public_id,
            name: $account->name,
            ownerId: $account->owner_id,
            createdAt: DateTimeImmutable::createFromInterface($account->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($account->updated_at),
        );
    }
}
