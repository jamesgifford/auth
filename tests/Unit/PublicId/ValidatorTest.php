<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Unit\PublicId;

use Progravity\Auth\PublicId\Checksum\NullChecksum;
use Progravity\Auth\PublicId\Generator;
use Progravity\Auth\PublicId\ValidationFailureReason;
use Progravity\Auth\PublicId\Validator;
use Progravity\Auth\Tests\Support\PublicIdConfigFactory;
use Progravity\Auth\Tests\TestCase;

class ValidatorTest extends TestCase
{
    private function validator(array $overrides = []): Validator
    {
        return new Validator(PublicIdConfigFactory::default($overrides));
    }

    public function test_empty_input_returns_empty_reason(): void
    {
        $result = $this->validator()->validate('');

        $this->assertTrue($result->isInvalid());
        $this->assertSame(ValidationFailureReason::Empty, $result->failureReason);
    }

    public function test_input_with_no_separator_returns_malformed(): void
    {
        $result = $this->validator()->validate('abcdef');

        $this->assertSame(ValidationFailureReason::Malformed, $result->failureReason);
    }

    public function test_uppercase_prefix_returns_invalid_prefix(): void
    {
        $result = $this->validator()->validate('USR_abcdefghijklmnopqres');

        $this->assertSame(ValidationFailureReason::InvalidPrefix, $result->failureReason);
    }

    public function test_digits_in_prefix_returns_invalid_prefix(): void
    {
        $result = $this->validator()->validate('us1_abcdefghijklmnopqres');

        $this->assertSame(ValidationFailureReason::InvalidPrefix, $result->failureReason);
    }

    public function test_empty_prefix_returns_invalid_prefix(): void
    {
        $result = $this->validator()->validate('_abcdefghijklmnopqres');

        $this->assertSame(ValidationFailureReason::InvalidPrefix, $result->failureReason);
    }

    public function test_prefix_too_long_returns_invalid_prefix(): void
    {
        $result = $this->validator()->validate('abcdefgh_xxxxxxxxxxxxxxxxxx00');

        $this->assertSame(ValidationFailureReason::InvalidPrefix, $result->failureReason);
    }

    public function test_remainder_shorter_than_body_length_returns_wrong_length(): void
    {
        $result = $this->validator()->validate('us_abc');

        $this->assertSame(ValidationFailureReason::WrongLength, $result->failureReason);
    }

    public function test_missing_checksum_when_enabled_returns_missing_checksum(): void
    {
        // body = 18 chars, no checksum suffix
        $result = $this->validator()->validate('us_abcdefghijklmnopqr');

        $this->assertSame(ValidationFailureReason::MissingChecksum, $result->failureReason);
    }

    public function test_remainder_too_long_with_checksum_enabled_returns_wrong_length(): void
    {
        // 18 body + 2 checksum + 3 extra = 23 chars after separator
        $result = $this->validator()->validate('us_abcdefghijklmnopqrstxyz');

        $this->assertSame(ValidationFailureReason::WrongLength, $result->failureReason);
    }

    public function test_unexpected_checksum_when_disabled_returns_unexpected_checksum(): void
    {
        $generator = new Generator(PublicIdConfigFactory::default([
            'checksum' => [
                'enabled' => false,
                'length' => 0,
                'strategy' => NullChecksum::class,
            ],
        ]));

        $valid = $generator->generate('us'); // length 2 + 1 + 18 = 21
        $tooLong = $valid.'xy'; // adds 2 trailing chars

        $validator = new Validator(PublicIdConfigFactory::default([
            'checksum' => [
                'enabled' => false,
                'length' => 0,
                'strategy' => NullChecksum::class,
            ],
        ]));
        $result = $validator->validate($tooLong);

        $this->assertSame(ValidationFailureReason::UnexpectedChecksum, $result->failureReason);
    }

    public function test_invalid_body_char_returns_invalid_body_char(): void
    {
        // valid prefix + 18 body chars where one char ('!') is not in alphabet, + 2 checksum
        $result = $this->validator()->validate('us_abcdefg!ijklmnopqrxx');

        $this->assertSame(ValidationFailureReason::InvalidBodyChar, $result->failureReason);
    }

    public function test_wrong_checksum_returns_invalid_checksum(): void
    {
        $generator = new Generator(PublicIdConfigFactory::default());
        $id = $generator->generate('us');

        // mutate the last 2 chars (checksum) to be wrong
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $current = substr($id, -2);
        $replacement = $current[0] === 'a' ? 'b' : 'a';
        $tampered = substr($id, 0, -2).$replacement.$replacement;

        // ensure body chars are still in alphabet (they are, both 'a' and 'b' are)
        $this->assertSame($alphabet, $alphabet); // sanity, keeps the variable used

        $validator = new Validator(PublicIdConfigFactory::default());
        $result = $validator->validate($tampered);

        $this->assertSame(ValidationFailureReason::InvalidChecksum, $result->failureReason);
    }

    public function test_valid_id_returns_parsed_parts(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);
        $validator = new Validator($config);

        $id = $generator->generate('usr');
        $result = $validator->validate($id);

        $this->assertTrue($result->isValid());
        $this->assertSame('usr', $result->prefix);
        $this->assertSame(18, strlen($result->body));
        $this->assertSame(2, strlen($result->checksum));
    }

    public function test_expected_prefix_match_returns_valid(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);
        $validator = new Validator($config);

        $id = $generator->generate('usr');
        $result = $validator->validate($id, 'usr');

        $this->assertTrue($result->isValid());
    }

    public function test_expected_prefix_mismatch_returns_wrong_prefix(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);
        $validator = new Validator($config);

        $id = $generator->generate('usr');
        $result = $validator->validate($id, 'proj');

        $this->assertSame(ValidationFailureReason::WrongPrefix, $result->failureReason);
    }

    public function test_round_trip_generate_then_validate(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);
        $validator = new Validator($config);

        for ($i = 0; $i < 25; $i++) {
            $id = $generator->generate('test');
            $result = $validator->validate($id);
            $this->assertTrue($result->isValid(), "round-trip failed for id={$id}");
            $this->assertSame('test', $result->prefix);
        }
    }

    public function test_disabled_checksum_id_without_suffix_validates(): void
    {
        $config = PublicIdConfigFactory::default([
            'checksum' => [
                'enabled' => false,
                'length' => 0,
                'strategy' => NullChecksum::class,
            ],
        ]);
        $generator = new Generator($config);
        $validator = new Validator($config);

        $id = $generator->generate('usr');
        $result = $validator->validate($id);

        $this->assertTrue($result->isValid());
        $this->assertSame('', $result->checksum);
    }

    public function test_is_valid_returns_boolean(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);
        $validator = new Validator($config);

        $id = $generator->generate('usr');

        $this->assertTrue($validator->isValid($id));
        $this->assertFalse($validator->isValid('garbage'));
    }

    public function test_parse_is_equivalent_to_validate_without_expected_prefix(): void
    {
        $config = PublicIdConfigFactory::default();
        $generator = new Generator($config);
        $validator = new Validator($config);

        $id = $generator->generate('usr');
        $parsed = $validator->parse($id);
        $validated = $validator->validate($id);

        $this->assertSame($parsed->valid, $validated->valid);
        $this->assertSame($parsed->prefix, $validated->prefix);
        $this->assertSame($parsed->body, $validated->body);
        $this->assertSame($parsed->checksum, $validated->checksum);
    }

    public function test_validator_does_not_throw_on_arbitrary_input(): void
    {
        $validator = $this->validator();

        // Each of these returns invalid rather than throwing.
        $inputs = [
            '',
            'no-separator',
            '_',
            '___',
            'A_B',
            "us_\x00\x00",
            'us_!@#$%^&*()_+',
            str_repeat('a', 1000),
            'usr_'.str_repeat('a', 1000),
        ];

        foreach ($inputs as $input) {
            $result = $validator->validate($input);
            $this->assertFalse(
                $result->isValid(),
                "expected invalid result for input ".var_export($input, true),
            );
        }
    }
}
