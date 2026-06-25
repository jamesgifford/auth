<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\PublicId;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use JamesGifford\Auth\PublicId\PublicId;
use JamesGifford\Auth\PublicId\Rules\ValidPublicId;
use JamesGifford\Auth\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidPublicIdRuleTest extends TestCase
{
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

    /**
     * Rows: input => [literal input or null when runtime-generated, tamper flag, expected message substring].
     *
     * The wrong-checksum case needs a freshly generated ID tampered at
     * runtime, so its input is null and built inside the test body.
     *
     * @return array<string, array{0: ?string, 1: bool, 2: string}>
     */
    public static function provideFailureReasonMessages(): array
    {
        return [
            'no separator' => ['noseparator', false, 'not a valid public ID'],
            'uppercase prefix' => ['USR_abcdefghijklmnopqraa', false, 'invalid prefix'],
            'invalid body char' => ['us_abcdefg!ijklmnopqrxx', false, 'invalid characters'],
            'wrong checksum' => [null, true, 'invalid checksum'],
            'short input' => ['us_abc', false, 'wrong length'],
        ];
    }

    #[DataProvider('provideFailureReasonMessages')]
    public function test_failure_reason_produces_expected_message(?string $input, bool $tamperChecksum, string $expectedMessage): void
    {
        if ($tamperChecksum) {
            $id = PublicId::generate('usr');
            $current = substr($id, -2);
            $replacement = $current[0] === 'a' ? 'b' : 'a';
            $input = substr($id, 0, -2).$replacement.$replacement;
        }

        $validator = $this->check($input);

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString($expectedMessage, implode(' ', $validator->errors()->all()));
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
