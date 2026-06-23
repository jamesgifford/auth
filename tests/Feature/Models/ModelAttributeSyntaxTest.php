<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Models;

use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use JamesGifford\Auth\Tests\Support\Fixtures\Inheritance\ChildInheritingModel;
use JamesGifford\Auth\Tests\Support\Fixtures\Inheritance\ChildOverridingModel;
use JamesGifford\Auth\Tests\Support\Fixtures\Inheritance\ParentFillableModel;
use JamesGifford\Auth\Tests\TestCase;

class ModelAttributeSyntaxTest extends TestCase
{
    // ---- Part 0: attribute inheritance behavior ----

    public function test_child_fillable_attribute_overrides_parent(): void
    {
        // Confirms override (not merge): only the most-derived class's
        // #[Fillable] is read. The published-subclass design depends on this.
        $this->assertSame(['alpha', 'beta'], (new ParentFillableModel)->getFillable());
        $this->assertSame(['alpha'], (new ChildOverridingModel)->getFillable());
    }

    public function test_child_without_attribute_inherits_parent_fillable(): void
    {
        $this->assertSame(['alpha', 'beta'], (new ChildInheritingModel)->getFillable());
    }

    public function test_hidden_attribute_is_read(): void
    {
        $this->assertSame(['secret'], (new ParentFillableModel)->getHidden());
    }

    // ---- Part 1: modernized base models behave identically ----

    public function test_account_fillable_is_unchanged(): void
    {
        $this->assertSame(['name', 'owner_id'], (new Account)->getFillable());

        // Mass-assignment respects the (attribute-declared) fillable.
        $account = (new Account)->fill(['name' => 'Acme', 'owner_id' => 7, 'public_id' => 'hax']);
        $this->assertSame('Acme', $account->name);
        $this->assertSame(7, $account->owner_id);
        $this->assertNull($account->public_id, 'public_id is not fillable and must not be mass-assigned.');
    }

    public function test_account_role_fillable_and_casts_unchanged(): void
    {
        $this->assertSame(['key', 'name', 'description', 'system', 'sort_order'], (new AccountRole)->getFillable());

        $casts = (new AccountRole)->getCasts();
        $this->assertSame('boolean', $casts['system']);
        $this->assertSame('integer', $casts['sort_order']);
    }

    public function test_account_user_fillable_and_casts_unchanged(): void
    {
        $this->assertSame(['account_id', 'user_id', 'account_role_id', 'joined_at'], (new AccountUser)->getFillable());
        $this->assertSame('datetime', (new AccountUser)->getCasts()['joined_at']);
    }

    public function test_no_base_model_declares_hidden(): void
    {
        // None of the three declared $hidden before; that's unchanged.
        $this->assertSame([], (new Account)->getHidden());
        $this->assertSame([], (new AccountRole)->getHidden());
        $this->assertSame([], (new AccountUser)->getHidden());
    }
}
