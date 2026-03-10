<?php

namespace Tests\Feature\Refund;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MissingGatewayAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_fails_gracefully_when_gateway_adapter_is_missing(): void
    {
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance.missing-adapter@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        Sanctum::actingAs($finance);

        $gateway = Gateway::query()->create(['code' => 'gateway_3', 'name' => 'Gateway 3', 'priority' => 1, 'is_active' => true]);
        $client = Client::query()->create(['name' => 'Client', 'email' => 'client.missing-adapter@test.local']);

        $transaction = Transaction::query()->create([
            'client_id' => $client->id,
            'gateway_id' => $gateway->id,
            'external_id' => 'external-123',
            'status' => 'paid',
            'amount' => 1000,
            'card_last_numbers' => '6063',
        ]);

        Http::fake();

        $response = $this->postJson('/api/refunds', ['transaction_id' => $transaction->id]);

        $response->assertOk()->assertJsonPath('data.refund.status', 'refund_failed');
        $this->assertSame('paid', $transaction->fresh()->status);
        Http::assertNothingSent();
    }
}
