<?php

declare(strict_types=1);

namespace Progravity\Auth\Transfers;

use Progravity\Auth\Models\AccountRole;

/**
 * Immutable snapshot of AccountRole state for use in events.
 *
 * Carries enough to identify the role (id, key) and display it (name) without
 * forcing listeners to re-query. The 'system' flag is included so listeners
 * can branch on whether the role is package-provided.
 */
final readonly class AccountRoleTransfer
{
    public function __construct(
        public int $id,
        public string $key,
        public string $name,
        public bool $system,
    ) {}

    public static function fromModel(AccountRole $role): self
    {
        return new self(
            id: $role->id,
            key: $role->key,
            name: $role->name,
            system: $role->system,
        );
    }
}
