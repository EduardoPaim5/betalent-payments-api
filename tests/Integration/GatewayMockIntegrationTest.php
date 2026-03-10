<?php

namespace Tests\Integration;

use App\Enums\UserRole;
use App\Models\Gateway;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GatewayMockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var((string) env('RUN_GATEWAY_INTEGRATION_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_GATEWAY_INTEGRATION_TESTS=true to run gateway mock integration tests.');
        }

        if (blank(config('services.gateways.gateway_1.base_url')) || blank(config('services.gateways.gateway_2.base_url'))) {
            $this->markTestSkipped('Gateway mock base URLs are not configured.');
        }
    }

    public function test_purchase_falls_back_to_gateway_2_against_real_mocks(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Integration Tester', 'email' => 'integration.purchase@betalent.local'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '100'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $transaction = Transaction::query()->with('attempts')->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', 'paid')
            ->assertJsonPath('data.transaction.gateway.code', 'gateway_2');

        $this->assertCount(2, $transaction->attempts);
    }

    public function test_refund_uses_real_gateway_mock_for_paid_transaction(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance.integration@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        $purchaseResponse = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Integration Tester', 'email' => 'integration.refund@betalent.local'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $purchaseResponse->assertCreated();

        $transaction = Transaction::query()->firstOrFail();
        Sanctum::actingAs($finance);

        $refundResponse = $this->postJson('/api/refunds', [
            'transaction_id' => $transaction->id,
        ]);

        $refundResponse
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'refunded');

        $this->assertSame('refunded', $transaction->fresh()->status);
    }
}
