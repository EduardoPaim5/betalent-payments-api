<?php

namespace Tests\Feature\Purchase;

use App\Models\Gateway;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MissingGatewayAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_treats_missing_gateway_adapter_as_technical_failure_and_falls_back(): void
    {
        Gateway::query()->create(['code' => 'gateway_3', 'name' => 'Gateway 3', 'priority' => 1, 'is_active' => true]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tester', 'email' => 'missing-adapter@test.local'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
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
        $this->assertSame('technical_error', $transaction->attempts()->orderBy('attempt_order')->firstOrFail()->error_type);
        Http::assertSentCount(1);
    }
}
