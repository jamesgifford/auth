<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Http;

use JamesGifford\Auth\Accounts\Services\AccountService;

class ListAccountsControllerTest extends HttpTestCase
{
    public function test_lists_the_users_accounts_as_json_with_current_flag(): void
    {
        ['user' => $user, 'account' => $first] = $this->userWithAccount('Ada');
        $second = app(AccountService::class)->create($user);

        $response = $this->actingAs($user)
            ->getJson(route('jamesgifford-auth.account.list'));

        $response->assertOk()
            ->assertJsonCount(2, 'accounts')
            ->assertJsonFragment([
                'public_id' => $first->public_id,
                'name' => $first->name,
                'is_current' => true,
            ])
            ->assertJsonFragment([
                'public_id' => $second->public_id,
                'name' => $second->name,
                'is_current' => false,
            ]);
    }

    public function test_list_response_is_json_not_a_view(): void
    {
        ['user' => $user] = $this->userWithAccount();

        $response = $this->actingAs($user)->get(route('jamesgifford-auth.account.list'));

        $response->assertOk();
        $this->assertJson($response->getContent());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }
}
