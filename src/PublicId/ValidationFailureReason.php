<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId;

/**
 * Distinct failure modes produced by {@see Validator}. Each case is a
 * specific reason the validator rejected an input — case names are
 * self-documenting.
 *
 * Callers branch on these to render specific messages or take corrective
 * action without parsing exception strings.
 */
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
