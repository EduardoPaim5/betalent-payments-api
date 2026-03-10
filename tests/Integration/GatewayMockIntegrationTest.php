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
        $product = $this->createProduct();

        $response = $this->purchase($product->id, 'integration.purchase@betalent.local', '100');

        $transaction = Transaction::query()->with('attempts')->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', 'paid')
            ->assertJsonPath('data.transaction.gateway.code', 'gateway_2');

        $this->assertCount(2, $transaction->attempts);
    }

    public function test_purchase_uses_gateway_1_when_primary_mock_approves(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);
        $product = $this->createProduct();

        $response = $this->purchase($product->id, 'integration.gateway1@betalent.local', '010');
        $transaction = Transaction::query()->with('attempts')->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', 'paid')
            ->assertJsonPath('data.transaction.gateway.code', 'gateway_1');

        $this->assertCount(1, $transaction->attempts);
        $this->assertSame('gateway_1', $transaction->gateway?->code);
    }

    public function test_purchase_fails_when_both_real_gateways_decline(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);
        $product = $this->createProduct();

        $response = $this->purchase($product->id, 'integration.fail@betalent.local', '200');
        $transaction = Transaction::query()->with('attempts')->firstOrFail();

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_failed');

        $this->assertSame('failed', $transaction->status);
        $this->assertCount(2, $transaction->attempts);
    }

    public function test_purchase_falls_back_after_gateway_1_authentication_failure_against_real_mocks(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);
        $product = $this->createProduct();

        config([
            'services.gateways.gateway_1.token' => 'invalid-token',
        ]);

        $response = $this->purchase($product->id, 'integration.gateway1-auth@betalent.local', '010');
        $transaction = Transaction::query()->with('attempts')->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', 'paid')
            ->assertJsonPath('data.transaction.gateway.code', 'gateway_2');

        $this->assertCount(2, $transaction->attempts);
        $this->assertSame('technical_error', $transaction->attempts()->orderBy('attempt_order')->firstOrFail()->error_type);
    }

    public function test_purchase_fails_when_gateway_2_authentication_is_invalid_against_real_mock(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $product = $this->createProduct();

        config([
            'services.gateways.gateway_2.auth_token' => 'invalid-token',
            'services.gateways.gateway_2.auth_secret' => 'invalid-secret',
        ]);

        $response = $this->purchase($product->id, 'integration.gateway2-auth@betalent.local', '010');
        $transaction = Transaction::query()->with('attempts')->firstOrFail();

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_failed');

        $this->assertSame('failed', $transaction->status);
        $this->assertCount(1, $transaction->attempts);
        $this->assertSame('business_error', $transaction->attempts()->firstOrFail()->error_type);
    }

    public function test_refund_uses_real_gateway_mock_for_paid_transaction(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $product = $this->createProduct();
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance.integration@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        $purchaseResponse = $this->purchase($product->id, 'integration.refund@betalent.local', '010');

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

    public function test_refund_fails_and_transaction_remains_paid_when_gateway_2_authentication_is_invalid(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $product = $this->createProduct();
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance.invalid-refund@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        $purchaseResponse = $this->purchase($product->id, 'integration.refund-auth@betalent.local', '010');
        $purchaseResponse->assertCreated();

        $transaction = Transaction::query()->firstOrFail();

        config([
            'services.gateways.gateway_2.auth_token' => 'invalid-token',
            'services.gateways.gateway_2.auth_secret' => 'invalid-secret',
        ]);

        Sanctum::actingAs($finance);

        $refundResponse = $this->postJson('/api/refunds', [
            'transaction_id' => $transaction->id,
        ]);

        $refundResponse
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'refund_failed');

        $this->assertSame('paid', $transaction->fresh()->status);
    }

    private function createProduct(): Product
    {
        return Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);
    }

    private function purchase(int $productId, string $email, string $cvv)
    {
        return $this->postJson('/api/purchases', [
            'client' => ['name' => 'Integration Tester', 'email' => $email],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => $cvv],
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
        ]);
    }
}
