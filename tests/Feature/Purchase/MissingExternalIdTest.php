<?php

namespace Tests\Feature\Purchase;

use App\Models\Gateway;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MissingExternalIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_fails_when_gateway_approves_without_external_id(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/login' => Http::response(['token' => 'fake-token']),
            '*/transactions' => Http::response([], 200),
        ]);

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_failed');
    }
}
