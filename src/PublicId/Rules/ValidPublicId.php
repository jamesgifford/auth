<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Progravity\Auth\PublicId\ValidationFailureReason;
use Progravity\Auth\PublicId\Validator;

/**
 * Laravel validation rule that wraps the package Validator.
 *
 * Usage:
 *   $request->validate([
 *       'user_id' => ['required', new ValidPublicId('usr')],
 *       'invitation_id' => ['nullable', new ValidPublicId(expectedPrefix: 'inv')],
 *       'reference' => ['required', new ValidPublicId()], // any prefix
 *   ]);
 */
final class ValidPublicId implements ValidationRule
{
    public function __construct(private readonly ?string $expectedPrefix = null)
    {
    }

    /**
     * Readability-oriented alternative to {@see __construct()}. Equivalent
     * to `new ValidPublicId($prefix)`.
     */
    public static function withPrefix(string $prefix): self
    {
        return new self($prefix);
    }

    /**
     * Run the rule. Calls $fail with a translatable message on failure.
     *
     * Note: Laravel skips non-implicit ValidationRules for empty/missing
     * values. Pair this rule with `required` if empty input should fail.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $result = app(Validator::class)->validate($value, $this->expectedPrefix);
        if ($result->isValid()) {
            return;
        }

        $fail($this->messageFor($result->failureReason));
    }

    private function messageFor(?ValidationFailureReason $reason): string
    {
        return match ($reason) {
            ValidationFailureReason::Empty => 'The :attribute cannot be empty.',
            ValidationFailureReason::Malformed => 'The :attribute is not a valid public ID.',
            ValidationFailureReason::InvalidPrefix => 'The :attribute has an invalid prefix.',
            ValidationFailureReason::WrongLength,
            ValidationFailureReason::MissingChecksum,
            ValidationFailureReason::UnexpectedChecksum => 'The :attribute is the wrong length.',
            ValidationFailureReason::InvalidBodyChar => 'The :attribute contains invalid characters.',
            ValidationFailureReason::InvalidChecksum => 'The :attribute has an invalid checksum (possible typo).',
            ValidationFailureReason::WrongPrefix => sprintf(
                'The :attribute must be a %s ID.',
                $this->expectedPrefix ?? 'valid',
            ),
            null => 'The :attribute is not a valid public ID.',
        };
    }
}
