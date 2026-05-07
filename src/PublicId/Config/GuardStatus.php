<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Config;

enum GuardStatus
{
    case NotYetLocked;
    case Locked;
    case Drifted;
}
