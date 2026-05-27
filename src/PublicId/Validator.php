<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId;

use Progravity\Auth\PublicId\Checksum\ChecksumStrategy;
use Progravity\Auth\PublicId\Config\PublicIdConfig;

/**
 * Parses and validates public ID strings.
 *
 * Always returns a {@see ValidationResult} — never throws on user input.
 * The result carries a {@see ValidationFailureReason} for branching on
 * specific failure modes.
 *
 * This is the underlying service behind {@see PublicId::validate()} and
 * {@see Rules\ValidPublicId}. Inject it directly via DI when you prefer
 * explicit dependencies.
 */
final class Validator
{
    private readonly ChecksumStrategy $checksumStrategy;

    public function __construct(private readonly PublicIdConfig $config)
    {
        $strategyClass = $config->checksumStrategy();
        $this->checksumStrategy = new $strategyClass;
    }

    /**
     * Validate a public ID, optionally requiring a specific prefix.
     */
    public function validate(string $publicId, ?string $expectedPrefix = null): ValidationResult
    {
        if ($publicId === '') {
            return ValidationResult::invalid(
                ValidationFailureReason::Empty,
                'public_id is empty',
            );
        }

        $separator = $this->config->separator();
        if (! str_contains($publicId, $separator)) {
            return ValidationResult::invalid(
                ValidationFailureReason::Malformed,
                "missing separator '{$separator}'",
            );
        }

        [$prefix, $remainder] = explode($separator, $publicId, 2);

        $maxPrefix = $this->config->prefixMaxLength();
        if (preg_match('/^[a-z]{1,'.$maxPrefix.'}$/', $prefix) !== 1) {
            return ValidationResult::invalid(
                ValidationFailureReason::InvalidPrefix,
                "prefix '{$prefix}' must be 1 to {$maxPrefix} lowercase letters",
            );
        }

        $bodyLength = $this->config->bodyLength();
        $checksumLength = $this->config->checksumLength();
        $checksumEnabled = $this->config->checksumEnabled();
        $expectedRemainderLength = $bodyLength + $checksumLength;
        $actualLength = strlen($remainder);

        if ($actualLength < $bodyLength) {
            return ValidationResult::invalid(
                ValidationFailureReason::WrongLength,
                "expected {$expectedRemainderLength} body+checksum chars, got {$actualLength}",
            );
        }
        if ($actualLength < $expectedRemainderLength && $checksumEnabled) {
            return ValidationResult::invalid(
                ValidationFailureReason::MissingChecksum,
                'expected checksum of length '.$checksumLength.', got '.($actualLength - $bodyLength),
            );
        }
        if ($actualLength > $expectedRemainderLength) {
            if (! $checksumEnabled) {
                return ValidationResult::invalid(
                    ValidationFailureReason::UnexpectedChecksum,
                    'checksum is disabled but '.($actualLength - $expectedRemainderLength).' trailing chars present',
                );
            }

            return ValidationResult::invalid(
                ValidationFailureReason::WrongLength,
                "expected {$expectedRemainderLength} body+checksum chars, got {$actualLength}",
            );
        }

        $body = substr($remainder, 0, $bodyLength);
        $checksum = substr($remainder, $bodyLength);

        $alphabet = $this->config->bodyAlphabet();
        foreach (mb_str_split($body) as $char) {
            if (! $alphabet->contains($char)) {
                return ValidationResult::invalid(
                    ValidationFailureReason::InvalidBodyChar,
                    "body contains '{$char}' which is not in alphabet",
                );
            }
        }

        if ($checksumEnabled) {
            if (! $this->checksumStrategy->verify($body, $checksum, $alphabet, $checksumLength)) {
                return ValidationResult::invalid(
                    ValidationFailureReason::InvalidChecksum,
                    "checksum '{$checksum}' does not match body",
                );
            }
        }

        if ($expectedPrefix !== null && $prefix !== $expectedPrefix) {
            return ValidationResult::invalid(
                ValidationFailureReason::WrongPrefix,
                "expected prefix '{$expectedPrefix}' but got '{$prefix}'",
            );
        }

        return ValidationResult::valid($prefix, $body, $checksum);
    }

    /**
     * Convenience boolean wrapper around {@see validate()}.
     */
    public function isValid(string $publicId, ?string $expectedPrefix = null): bool
    {
        return $this->validate($publicId, $expectedPrefix)->isValid();
    }

    /**
     * Parse a public ID. Equivalent to {@see validate()} with no expected prefix.
     */
    public function parse(string $publicId): ValidationResult
    {
        return $this->validate($publicId);
    }
}
