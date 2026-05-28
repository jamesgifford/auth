<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\PublicId;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\PublicId\Rules\ValidPublicId;
use JamesGifford\Auth\Tests\TestCase;

class ValidPublicIdRuleTest extends TestCase
{
    public function test_valid_generated_public_id_passes(): void
    {
        $id = PublicId::generate('usr');

        $this->assertTrue($this->check($id)->passes());
    }

    public function test_garbage_input_fails(): void
    {
        $this->assertFalse($this->check('garbage')->passes());
    }

    public function test_wrong_prefix_fails_when_expected_prefix_set(): void
    {
        $id = PublicId::generate('usr');

        $validator = $this->check($id, 'proj');

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('proj', implode(' ', $validator->errors()->all()));
    }

    public function test_correct_prefix_passes_when_expected_prefix_set(): void
    {
        $id = PublicId::generate('usr');

        $this->assertTrue($this->check($id, 'usr')->passes());
    }

    public function test_empty_input_produces_empty_message(): void
    {
        // Laravel's non-implicit ValidationRule skips empty values, so we
        // exercise the rule directly to confirm the Empty → message mapping.
        $rule = new ValidPublicId;
        $message = null;
        $rule->validate('id', '', function (string $msg) use (&$message): void {
            $message = $msg;
        });

        $this->assertSame('The :attribute cannot be empty.', $message);
    }

    public function test_input_without_separator_produces_not_a_valid_message(): void
    {
        $validator = $this->check('noseparator');

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('not a valid public ID', implode(' ', $validator->errors()->all()));
    }

    public function test_input_with_uppercase_prefix_produces_invalid_prefix_message(): void
    {
        $validator = $this->check('USR_abcdefghijklmnopqraa');

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('invalid prefix', implode(' ', $validator->errors()->all()));
    }

    public function test_input_with_invalid_body_char_produces_invalid_chars_message(): void
    {
        $validator = $this->check('us_abcdefg!ijklmnopqrxx');

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('invalid characters', implode(' ', $validator->errors()->all()));
    }

    public function test_input_with_wrong_checksum_produces_checksum_message(): void
    {
        $id = PublicId::generate('usr');
        $current = substr($id, -2);
        $replacement = $current[0] === 'a' ? 'b' : 'a';
        $tampered = substr($id, 0, -2).$replacement.$replacement;

        $validator = $this->check($tampered);

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('invalid checksum', implode(' ', $validator->errors()->all()));
    }

    public function test_short_input_produces_wrong_length_message(): void
    {
        $validator = $this->check('us_abc');

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('wrong length', implode(' ', $validator->errors()->all()));
    }

    public function test_non_string_value_produces_must_be_string_message(): void
    {
        $cases = [
            ['id' => 123, 'expected' => 'must be a string'],
            ['id' => [], 'expected' => 'must be a string'],
            ['id' => null, 'expected' => null], // 'nullable' rule absent — but our rule is reached only if present
        ];

        // For the int case
        $validator = ValidatorFacade::make(['id' => 123], ['id' => [new ValidPublicId]]);
        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('must be a string', implode(' ', $validator->errors()->all()));

        // For the array case
        $validator = ValidatorFacade::make(['id' => []], ['id' => [new ValidPublicId]]);
        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('must be a string', implode(' ', $validator->errors()->all()));
    }

    public function test_with_prefix_factory_equivalent_to_constructor(): void
    {
        $id = PublicId::generate('usr');

        $factoryRule = ValidPublicId::withPrefix('proj');
        $constructorRule = new ValidPublicId('proj');

        $factoryValidator = ValidatorFacade::make(['id' => $id], ['id' => [$factoryRule]]);
        $constructorValidator = ValidatorFacade::make(['id' => $id], ['id' => [$constructorRule]]);

        $this->assertSame($factoryValidator->passes(), $constructorValidator->passes());
        $this->assertSame(
            implode(' ', $factoryValidator->errors()->all()),
            implode(' ', $constructorValidator->errors()->all()),
        );
    }

    private function check(mixed $value, ?string $expectedPrefix = null): Validator
    {
        return ValidatorFacade::make(
            ['id' => $value],
            ['id' => [$expectedPrefix === null ? new ValidPublicId : new ValidPublicId($expectedPrefix)]],
        );
    }
}
