<?php

namespace Tests\Feature\Purchase;

use App\Models\Gateway;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreatePurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_tries_next_gateway_after_failure(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/login' => Http::response(['token' => 'fake-token']),
            '*/transactions' => Http::response(['message' => 'declined'], 422),
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', 'paid')
            ->assertJsonMissingPath('data.transaction.external_id');
    }

    public function test_purchase_continues_to_secondary_gateway_after_connection_exception(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/login' => Http::response(['token' => 'fake-token']),
            '*/transactions' => function () {
                throw new ConnectionException('Gateway timeout');
            },
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $transaction = Transaction::query()->firstOrFail();

        $response->assertCreated()->assertJsonPath('data.transaction.gateway.code', 'gateway_2');
        $this->assertSame('paid', $transaction->status);
        $this->assertCount(2, $transaction->attempts);
        $this->assertSame('technical_error', $transaction->attempts()->orderBy('attempt_order')->firstOrFail()->error_type);
    }
}
