<?php

namespace Tests\Feature\Purchase;

use App\Models\Client;
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

    public function test_purchase_amount_is_calculated_from_multiple_products_and_quantities(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $notebook = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);
        $monitor = Product::query()->create(['name' => 'Monitor', 'amount' => 1500, 'is_active' => true]);

        Http::fake([
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $notebook->id, 'quantity' => 2],
                ['product_id' => $monitor->id, 'quantity' => 1],
            ],
        ]);

        $transaction = Transaction::query()->with('products')->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.transaction.amount', 3500);

        $this->assertSame(3500, $transaction->amount);
        $this->assertSame(2000, $transaction->products->firstWhere('id', $notebook->id)?->pivot->line_total);
        $this->assertSame(1500, $transaction->products->firstWhere('id', $monitor->id)?->pivot->line_total);
    }

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

    public function test_purchase_replays_existing_transaction_for_same_idempotency_key(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $payload = [
            'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $firstResponse = $this
            ->withHeader('Idempotency-Key', 'purchase-checkout-1')
            ->postJson('/api/purchases', $payload);

        $secondResponse = $this
            ->withHeader('Idempotency-Key', 'purchase-checkout-1')
            ->postJson('/api/purchases', $payload);

        $firstTransactionId = $firstResponse->json('data.transaction.id');
        $secondTransactionId = $secondResponse->json('data.transaction.id');

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.replayed', false);

        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.transaction.id', $firstTransactionId);

        $this->assertSame($firstTransactionId, $secondTransactionId);
        $this->assertSame(1, Transaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_purchase_rejects_reused_idempotency_key_with_different_payload(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $this
            ->withHeader('Idempotency-Key', 'purchase-checkout-2')
            ->postJson('/api/purchases', [
                'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
                'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $this
            ->withHeader('Idempotency-Key', 'purchase-checkout-2')
            ->postJson('/api/purchases', [
                'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
                'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.idempotency_key.0', 'This idempotency key was already used with a different stable purchase payload.');

        $this->assertSame(1, Transaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_purchase_rejects_reused_idempotency_key_when_card_changes_with_same_last_four_digits(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $this
            ->withHeader('Idempotency-Key', 'purchase-checkout-3')
            ->postJson('/api/purchases', [
                'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
                'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $this
            ->withHeader('Idempotency-Key', 'purchase-checkout-3')
            ->postJson('/api/purchases', [
                'client' => ['name' => 'Tester', 'email' => 'tester@email.com'],
                'payment' => ['card_number' => '4111000000006063', 'cvv' => '010'],
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.idempotency_key.0', 'This idempotency key was already used with a different stable purchase payload.');

        $this->assertSame(1, Transaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_existing_client_name_is_not_mutated_by_public_purchase_payload(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);
        $client = Client::query()->create(['name' => 'Original Client', 'email' => 'tester@email.com']);

        Http::fake([
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tampered Client', 'email' => 'tester@email.com'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $this->assertSame('Original Client', $client->fresh()->name);
    }
}
