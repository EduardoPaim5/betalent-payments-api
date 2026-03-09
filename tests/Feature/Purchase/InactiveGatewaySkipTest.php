<?php

namespace Tests\Feature\Purchase;

use App\Models\Gateway;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InactiveGatewaySkipTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_gateway_is_not_called_during_fallback(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => false]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.transaction.gateway.code', 'gateway_2');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/transacoes');
        });
    }
}
