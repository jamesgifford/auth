<?php

declare(strict_types=1);

namespace Progravity\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Progravity\Auth\Transfers\AccountTransfer;

/**
 * Dispatched after AccountService::restore() commits.
 *
 * Not dispatched when restore() is called on an already-non-deleted account
 * (the no-op path). The transfer reflects post-restore state.
 */
final class AccountRestored
{
    use Dispatchable;

    public function __construct(
        public readonly AccountTransfer $account,
    ) {}
}
