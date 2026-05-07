<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId;

/**
 * Result of a public ID validation run. Use {@see isValid()} to branch;
 * on success, the parsed prefix/body/checksum are populated; on failure,
 * `failureReason` and `failureDetail` describe why.
 *
 * Construct via the static factories — never directly.
 */
final class ValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $prefix,
        public readonly ?string $body,
        public readonly ?string $checksum,
        public readonly ?ValidationFailureReason $failureReason,
        public readonly ?string $failureDetail,
    ) {
    }

    public static function valid(string $prefix, string $body, string $checksum): self
    {
        return new self(true, $prefix, $body, $checksum, null, null);
    }

    public static function invalid(ValidationFailureReason $reason, string $detail): self
    {
        return new self(false, null, null, null, $reason, $detail);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function isInvalid(): bool
    {
        return ! $this->valid;
    }
}
