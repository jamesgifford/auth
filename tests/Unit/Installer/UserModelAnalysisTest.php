<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\Installer;

use JamesGifford\Auth\Installer\UserModelAnalysis;
use PHPUnit\Framework\TestCase;

class UserModelAnalysisTest extends TestCase
{
    public function test_needs_modification_true_when_any_flag_is_false(): void
    {
        // Missing HasPublicId
        $a = $this->build(hasHasPublicId: false, hasHasAccounts: true, hasPrefix: true);
        $this->assertTrue($a->needsModification());

        // Missing HasAccounts
        $b = $this->build(hasHasPublicId: true, hasHasAccounts: false, hasPrefix: true);
        $this->assertTrue($b->needsModification());

        // Missing publicIdPrefix
        $c = $this->build(hasHasPublicId: true, hasHasAccounts: true, hasPrefix: false);
        $this->assertTrue($c->needsModification());
    }

    public function test_needs_modification_false_when_all_three_flags_true(): void
    {
        $a = $this->build(hasHasPublicId: true, hasHasAccounts: true, hasPrefix: true);
        $this->assertFalse($a->needsModification());
    }

    public function test_is_modifiable_true_when_all_conditions_met(): void
    {
        $a = $this->build();
        $this->assertTrue($a->isModifiable());
    }

    public function test_is_modifiable_false_when_file_missing(): void
    {
        $a = $this->build(fileExists: false);
        $this->assertFalse($a->isModifiable());
    }

    public function test_is_modifiable_false_when_not_parseable(): void
    {
        $a = $this->build(parseable: false);
        $this->assertFalse($a->isModifiable());
    }

    public function test_is_modifiable_false_when_does_not_extend_authenticatable(): void
    {
        $a = $this->build(extendsAuth: false);
        $this->assertFalse($a->isModifiable());
    }

    public function test_is_modifiable_false_when_unusual_structure(): void
    {
        $a = $this->build(unusual: true, unusualReason: 'multiple classes');
        $this->assertFalse($a->isModifiable());
    }

    private function build(
        bool $fileExists = true,
        bool $parseable = true,
        bool $extendsAuth = true,
        bool $hasHasPublicId = false,
        bool $hasHasAccounts = false,
        bool $hasPrefix = false,
        bool $unusual = false,
        ?string $unusualReason = null,
    ): UserModelAnalysis {
        return new UserModelAnalysis(
            fileExists: $fileExists,
            parseable: $parseable,
            className: 'User',
            namespace: 'App\\Models',
            extendsAuthenticatable: $extendsAuth,
            hasHasPublicIdTrait: $hasHasPublicId,
            hasHasAccountsTrait: $hasHasAccounts,
            hasPublicIdPrefixMethod: $hasPrefix,
            hasUnusualStructure: $unusual,
            unusualReason: $unusualReason,
        );
    }
}
