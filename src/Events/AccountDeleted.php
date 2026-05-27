<?php

declare(strict_types=1);

namespace Progravity\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Progravity\Auth\Transfers\AccountTransfer;

/**
 * Dispatched after AccountService::delete() commits the soft delete.
 *
 * The account row still exists (with deleted_at set); membership rows are
 * preserved. Listeners that need to react to permanent removal should listen
 * for {@see AccountForceDeleted} instead.
 */
final class AccountDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly AccountTransfer $account,
    ) {}
}
