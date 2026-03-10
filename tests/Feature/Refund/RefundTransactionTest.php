<?php

namespace Tests\Feature\Refund;

use App\Enums\RefundStatus;
use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RefundTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_can_refund_paid_transaction(): void
    {
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        Sanctum::actingAs($finance);

        $gateway = Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $client = Client::query()->create(['name' => 'Client', 'email' => 'client@test.local']);

        $transaction = Transaction::query()->create([
            'client_id' => $client->id,
            'gateway_id' => $gateway->id,
            'external_id' => 'external-123',
            'status' => TransactionStatus::PAID->value,
            'amount' => 1000,
            'card_last_numbers' => '6063',
        ]);

        Http::fake([
            '*/transacoes/reembolso' => Http::response(['id' => 'refund-1'], 200),
        ]);

        $response = $this->postJson('/api/refunds', ['transaction_id' => $transaction->id]);

        $response->assertOk()->assertJsonPath('data.refund.status', 'refunded');
    }

    public function test_refund_is_rejected_for_non_paid_transaction(): void
    {
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        Sanctum::actingAs($finance);

        $gateway = Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $client = Client::query()->create(['name' => 'Client', 'email' => 'client@test.local']);

        $transaction = Transaction::query()->create([
            'client_id' => $client->id,
            'gateway_id' => $gateway->id,
            'external_id' => 'external-123',
            'status' => TransactionStatus::REFUNDED->value,
            'amount' => 1000,
            'card_last_numbers' => '6063',
        ]);

        $response = $this->postJson('/api/refunds', ['transaction_id' => $transaction->id]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_failed_refund_keeps_transaction_paid_for_retry(): void
    {
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        Sanctum::actingAs($finance);

        $gateway = Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $client = Client::query()->create(['name' => 'Client', 'email' => 'client@test.local']);

        $transaction = Transaction::query()->create([
            'client_id' => $client->id,
            'gateway_id' => $gateway->id,
            'external_id' => 'external-123',
            'correlation_id' => '11111111-1111-1111-1111-111111111112',
            'status' => TransactionStatus::PAID->value,
            'amount' => 1000,
            'card_last_numbers' => '6063',
        ]);

        Http::fake([
            '*/transacoes/reembolso' => Http::response(['mensagem' => 'declined'], 422),
        ]);

        $response = $this->postJson('/api/refunds', ['transaction_id' => $transaction->id]);

        $response->assertOk()->assertJsonPath('data.refund.status', 'refund_failed');
        $this->assertSame(TransactionStatus::PAID->value, $transaction->fresh()->status);
    }

    public function test_refund_is_rejected_when_another_refund_is_in_progress(): void
    {
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        Sanctum::actingAs($finance);

        $gateway = Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $client = Client::query()->create(['name' => 'Client', 'email' => 'client@test.local']);

        $transaction = Transaction::query()->create([
            'client_id' => $client->id,
            'gateway_id' => $gateway->id,
            'external_id' => 'external-123',
            'correlation_id' => '11111111-1111-1111-1111-111111111113',
            'status' => TransactionStatus::PAID->value,
            'amount' => 1000,
            'card_last_numbers' => '6063',
        ]);

        $transaction->refunds()->create([
            'gateway_id' => $gateway->id,
            'status' => RefundStatus::PROCESSING->value,
        ]);

        $response = $this->postJson('/api/refunds', ['transaction_id' => $transaction->id]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }
}
