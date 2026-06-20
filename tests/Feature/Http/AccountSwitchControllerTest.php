<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class AccountSwitchControllerTest extends HttpTestCase
{
    public function test_member_can_switch_current_account(): void
    {
        ['user' => $user, 'account' => $first] = $this->userWithAccount();
        $second = app(AccountService::class)->create($user);
        $user->switchToAccount($second);
        $user = $user->fresh();
        $this->assertSame($second->id, $user->current_account_id);

        $response = $this->actingAs($user)
            ->post(route('jamesgifford-auth.account.switch', $first));

        $response->assertRedirect();
        $this->assertSame($first->id, $user->fresh()->current_account_id);
    }

    public function test_non_member_switch_is_rejected_and_does_not_change_current_account(): void
    {
        ['user' => $user, 'account' => $own] = $this->userWithAccount();

        // An account owned by someone else — the user is not a member.
        $stranger = User::factory()->create();
        $foreign = app(AccountService::class)->create($stranger);

        $response = $this->actingAs($user)
            ->postJson(route('jamesgifford-auth.account.switch', $foreign));

        $response->assertStatus(403);
        // Unchanged: still pointing at their own account.
        $this->assertSame($own->id, $user->fresh()->current_account_id);
    }

    public function test_unauthenticated_switch_is_handled_by_auth_middleware(): void
    {
        ['account' => $account] = $this->userWithAccount();

        // JSON request so the auth middleware returns 401 rather than
        // redirecting to a (nonexistent) login route.
        $this->postJson(route('jamesgifford-auth.account.switch', $account))
            ->assertStatus(401);
    }

    public function test_switch_returns_json_when_requested(): void
    {
        ['user' => $user, 'account' => $account] = $this->userWithAccount();

        $response = $this->actingAs($user)
            ->postJson(route('jamesgifford-auth.account.switch', $account));

        $response->assertOk()->assertJson(['current_account' => $account->public_id]);
    }
}
