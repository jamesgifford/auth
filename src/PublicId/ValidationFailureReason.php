<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId;

enum ValidationFailureReason
{
    case Empty;
    case Malformed;
    case InvalidPrefix;
    case WrongLength;
    case InvalidBodyChar;
    case InvalidChecksum;
    case MissingChecksum;
    case UnexpectedChecksum;
    case WrongPrefix;
}
