<?php

namespace Tests\Feature\Authorization;

use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InternalDataAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_receives_unauthorized_for_private_route(): void
    {
        $this->getJson('/api/gateways')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_user_cannot_list_gateways(): void
    {
        $user = User::query()->create([
            'name' => 'Common User',
            'email' => 'user@test.local',
            'password' => 'password123',
            'role' => UserRole::USER,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/gateways')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_user_cannot_list_clients(): void
    {
        $user = User::query()->create([
            'name' => 'Common User',
            'email' => 'user@test.local',
            'password' => 'password123',
            'role' => UserRole::USER,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/clients')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_user_cannot_list_transactions(): void
    {
        $user = User::query()->create([
            'name' => 'Common User',
            'email' => 'user@test.local',
            'password' => 'password123',
            'role' => UserRole::USER,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/transactions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_finance_can_view_transaction_without_external_identifiers(): void
    {
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        $gateway = Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $client = Client::query()->create(['name' => 'Client', 'email' => 'client@test.local']);
        $transaction = Transaction::query()->create([
            'client_id' => $client->id,
            'gateway_id' => $gateway->id,
            'external_id' => 'external-123',
            'correlation_id' => '11111111-1111-1111-1111-111111111111',
            'status' => TransactionStatus::PAID->value,
            'amount' => 1000,
            'card_last_numbers' => '6063',
        ]);

        Sanctum::actingAs($finance);

        $this->getJson('/api/transactions/'.$transaction->id)
            ->assertOk()
            ->assertJsonMissingPath('data.transaction.external_id');
    }
}
