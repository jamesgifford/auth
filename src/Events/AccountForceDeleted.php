<?php

declare(strict_types=1);

namespace Progravity\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Progravity\Auth\Transfers\AccountTransfer;

/**
 * Dispatched after AccountService::forceDelete() commits.
 *
 * The transfer is snapshotted before the row is removed (since fromModel()
 * would have nothing to read afterward), but the event itself fires after
 * commit — transfers hold their data independently of the model lifecycle.
 *
 * Membership rows and current_account_id pointers are cleaned up by the
 * schema's FK cascade/nullOnDelete, not by Eloquent — so AccountUser model
 * events do NOT fire on this path.
 */
final class AccountForceDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly AccountTransfer $account,
    ) {}
}
