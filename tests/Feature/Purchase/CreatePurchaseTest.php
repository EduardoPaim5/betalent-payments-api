<?php

namespace Tests\Feature\Purchase;

use App\Enums\UserRole;
use App\Models\Gateway;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
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

        $response->assertCreated()->assertJsonPath('data.transaction.status', 'paid');
    }
}
