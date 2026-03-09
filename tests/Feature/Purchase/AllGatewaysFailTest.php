<?php

namespace Tests\Feature\Purchase;

use App\Models\Gateway;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllGatewaysFailTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_fails_when_all_gateways_fail(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/login' => Http::response(['token' => 'fake-token']),
            '*/transactions' => Http::response(['message' => 'declined'], 422),
            '*/transacoes' => Http::response(['mensagem' => 'declined'], 422),
        ]);

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $transactionId = $response->json('error.details.transaction_id');

        $response->assertStatus(422)->assertJsonPath('error.code', 'payment_failed');
        $this->assertNotNull($transactionId);
        $this->assertSame('failed', Transaction::query()->findOrFail($transactionId)->status);
    }
}
