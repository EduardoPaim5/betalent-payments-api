<?php

namespace Tests\Feature\Purchase;

use App\Models\Gateway;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NoActiveGatewaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_fails_cleanly_when_no_gateways_are_active(): void
    {
        Gateway::query()->create(['code' => 'gateway_1', 'name' => 'Gateway 1', 'priority' => 1, 'is_active' => false]);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 2, 'is_active' => false]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake();

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tester', 'email' => 'no-active@test.local'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $transaction = Transaction::query()->with('attempts')->firstOrFail();

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_failed')
            ->assertJsonPath('error.details.transaction_id', $transaction->id)
            ->assertJsonPath('error.details.failure_reason', 'No active gateways available.');

        $this->assertSame('failed', $transaction->status);
        $this->assertSame('No active gateways available.', $transaction->failure_reason);
        $this->assertCount(0, $transaction->attempts);
        Http::assertNothingSent();
    }
}
