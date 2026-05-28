<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\PublicId\Config;

use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\Tests\Support\PublicIdConfigFactory;
use JamesGifford\Auth\Tests\TestCase;
use LogicException;

class PublicIdConfigFactoryTest extends TestCase
{
    public function test_default_with_no_overrides_succeeds(): void
    {
        $config = PublicIdConfigFactory::default();

        $this->assertInstanceOf(PublicIdConfig::class, $config);
        $this->assertSame('_', $config->separator());
        $this->assertSame('lowercase_alphanumeric', $config->bodyAlphabetConfigValue());
    }

    public function test_overriding_alphabet_to_value_containing_default_separator_throws(): void
    {
        $this->expectException(LogicException::class);

        PublicIdConfigFactory::default([
            'body' => ['alphabet' => 'abc_def'],
        ]);
    }

    public function test_conflict_exception_message_names_separator_and_alphabet(): void
    {
        try {
            PublicIdConfigFactory::default([
                'body' => ['alphabet' => 'abc_def'],
            ]);
            $this->fail('Expected LogicException');
        } catch (LogicException $e) {
            $this->assertStringContainsString("'_'", $e->getMessage());
            $this->assertStringContainsString('abc_def', $e->getMessage());
            $this->assertStringContainsString('separator', $e->getMessage());
        }
    }

    public function test_overriding_both_alphabet_and_non_conflicting_separator_succeeds(): void
    {
        $config = PublicIdConfigFactory::default([
            'separator' => '|',
            'body' => ['alphabet' => 'abc_def'],
        ]);

        $this->assertSame('|', $config->separator());
        $this->assertSame('abc_def', $config->bodyAlphabetConfigValue());
    }

    public function test_overriding_alphabet_to_non_conflicting_preset_succeeds(): void
    {
        $config = PublicIdConfigFactory::default([
            'body' => ['alphabet' => 'crockford'],
        ]);

        $this->assertSame('_', $config->separator());
        $this->assertSame('crockford', $config->bodyAlphabetConfigValue());
        $this->assertSame(32, $config->bodyAlphabet()->size());
    }
}
