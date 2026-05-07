<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Unit\PublicId;

use OutOfBoundsException;
use Progravity\Auth\PublicId\Alphabet;
use Progravity\Auth\PublicId\AlphabetRegistry;
use Progravity\Auth\PublicId\Exceptions\InvalidAlphabetException;
use Progravity\Auth\Tests\TestCase;

class AlphabetRegistryTest extends TestCase
{
    public function test_resolve_returns_alphabet_for_lowercase_alpha_preset(): void
    {
        $registry = new AlphabetRegistry;

        $alphabet = $registry->resolve('lowercase_alpha');

        $this->assertSame('abcdefghijklmnopqrstuvwxyz', $alphabet->toString());
    }

    public function test_resolve_returns_alphabet_for_lowercase_alphanumeric_preset(): void
    {
        $registry = new AlphabetRegistry;

        $alphabet = $registry->resolve('lowercase_alphanumeric');

        $this->assertSame('abcdefghijklmnopqrstuvwxyz0123456789', $alphabet->toString());
    }

    public function test_resolve_returns_alphabet_for_uppercase_alpha_preset(): void
    {
        $registry = new AlphabetRegistry;

        $alphabet = $registry->resolve('uppercase_alpha');

        $this->assertSame('ABCDEFGHIJKLMNOPQRSTUVWXYZ', $alphabet->toString());
    }

    public function test_resolve_returns_alphabet_for_uppercase_alphanumeric_preset(): void
    {
        $registry = new AlphabetRegistry;

        $alphabet = $registry->resolve('uppercase_alphanumeric');

        $this->assertSame('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $alphabet->toString());
    }

    public function test_resolve_returns_alphabet_for_mixed_alphanumeric_preset(): void
    {
        $registry = new AlphabetRegistry;

        $alphabet = $registry->resolve('mixed_alphanumeric');

        $this->assertSame(62, $alphabet->size());
    }

    public function test_crockford_preset_excludes_iluo_letters(): void
    {
        $registry = new AlphabetRegistry;

        $alphabet = $registry->resolve('crockford');

        $this->assertSame(32, $alphabet->size());
        $this->assertFalse($alphabet->contains('I'));
        $this->assertFalse($alphabet->contains('L'));
        $this->assertFalse($alphabet->contains('O'));
        $this->assertFalse($alphabet->contains('U'));
        $this->assertTrue($alphabet->contains('0'));
        $this->assertTrue($alphabet->contains('9'));
        $this->assertTrue($alphabet->contains('A'));
        $this->assertTrue($alphabet->contains('Z'));
    }

    public function test_nolookalikes_preset_excludes_confusable_chars(): void
    {
        $registry = new AlphabetRegistry;

        $alphabet = $registry->resolve('nolookalikes');

        $this->assertSame(31, $alphabet->size());
        $this->assertFalse($alphabet->contains('i'));
        $this->assertFalse($alphabet->contains('l'));
        $this->assertFalse($alphabet->contains('o'));
        $this->assertFalse($alphabet->contains('0'));
        $this->assertFalse($alphabet->contains('1'));
    }

    public function test_resolve_treats_unknown_value_as_raw_alphabet(): void
    {
        $registry = new AlphabetRegistry;

        $alphabet = $registry->resolve('xyz789');

        $this->assertSame('xyz789', $alphabet->toString());
    }

    public function test_resolve_caches_preset_instances(): void
    {
        $registry = new AlphabetRegistry;

        $first = $registry->resolve('lowercase_alpha');
        $second = $registry->resolve('lowercase_alpha');

        $this->assertSame($first, $second);
    }

    public function test_has_returns_true_for_built_in_preset(): void
    {
        $registry = new AlphabetRegistry;

        $this->assertTrue($registry->has('lowercase_alpha'));
        $this->assertTrue($registry->has('crockford'));
        $this->assertTrue($registry->has('nolookalikes'));
    }

    public function test_has_returns_false_for_unknown_preset(): void
    {
        $registry = new AlphabetRegistry;

        $this->assertFalse($registry->has('does_not_exist'));
    }

    public function test_get_returns_preset_alphabet(): void
    {
        $registry = new AlphabetRegistry;

        $alphabet = $registry->get('lowercase_alpha');

        $this->assertInstanceOf(Alphabet::class, $alphabet);
        $this->assertSame('abcdefghijklmnopqrstuvwxyz', $alphabet->toString());
    }

    public function test_get_throws_for_unknown_preset(): void
    {
        $registry = new AlphabetRegistry;

        $this->expectException(OutOfBoundsException::class);
        $registry->get('does_not_exist');
    }

    public function test_names_returns_sorted_list_including_built_ins(): void
    {
        $registry = new AlphabetRegistry;

        $names = $registry->names();

        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);

        $this->assertContains('lowercase_alpha', $names);
        $this->assertContains('lowercase_alphanumeric', $names);
        $this->assertContains('uppercase_alpha', $names);
        $this->assertContains('uppercase_alphanumeric', $names);
        $this->assertContains('mixed_alphanumeric', $names);
        $this->assertContains('crockford', $names);
        $this->assertContains('nolookalikes', $names);
    }

    public function test_constructor_accepts_custom_preset_resolvable_via_resolve(): void
    {
        $registry = new AlphabetRegistry(['custom' => 'xyz']);

        $alphabet = $registry->resolve('custom');

        $this->assertSame('xyz', $alphabet->toString());
    }

    public function test_constructor_accepts_custom_preset_resolvable_via_get(): void
    {
        $registry = new AlphabetRegistry(['custom' => 'xyz']);

        $alphabet = $registry->get('custom');

        $this->assertSame('xyz', $alphabet->toString());
    }

    public function test_custom_preset_overrides_built_in_on_name_collision(): void
    {
        $registry = new AlphabetRegistry([
            'lowercase_alpha' => 'xyz',
        ]);

        $this->assertSame('xyz', $registry->resolve('lowercase_alpha')->toString());
    }

    public function test_constructor_throws_when_custom_preset_is_invalid(): void
    {
        $this->expectException(InvalidAlphabetException::class);

        new AlphabetRegistry(['bad' => 'aab']);
    }

    public function test_constructor_throws_when_custom_preset_is_too_short(): void
    {
        $this->expectException(InvalidAlphabetException::class);

        new AlphabetRegistry(['bad' => 'a']);
    }
}
