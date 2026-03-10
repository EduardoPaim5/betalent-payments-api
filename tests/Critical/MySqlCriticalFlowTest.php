<?php

namespace Tests\Critical;

use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentTransactionCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MySqlCriticalFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var((string) env('RUN_CRITICAL_MYSQL_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_CRITICAL_MYSQL_TESTS=true to run the CriticalMySql suite.');
        }

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('CriticalMySql requires TEST_DB_CONNECTION=mysql.');
        }
    }

    public function test_purchase_amount_is_calculated_and_persisted_against_mysql(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $notebook = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);
        $monitor = Product::query()->create(['name' => 'Monitor', 'amount' => 1500, 'is_active' => true]);

        Http::fake([
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $response = $this->postJson('/api/purchases', [
            'client' => ['name' => 'Tester', 'email' => 'mysql.amount@test.local'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $notebook->id, 'quantity' => 2],
                ['product_id' => $monitor->id, 'quantity' => 1],
            ],
        ]);

        $transaction = Transaction::query()->with('products')->firstOrFail();

        $response->assertCreated()->assertJsonPath('data.transaction.amount', 3500);
        $this->assertSame(3500, $transaction->amount);
        $this->assertSame(2000, $transaction->products->firstWhere('id', $notebook->id)?->pivot->line_total);
        $this->assertSame(1500, $transaction->products->firstWhere('id', $monitor->id)?->pivot->line_total);
    }

    public function test_purchase_falls_back_and_records_attempts_against_mysql(): void
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
            'client' => ['name' => 'Tester', 'email' => 'mysql.fallback@test.local'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $transaction = Transaction::query()->with(['gateway', 'attempts'])->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', 'paid')
            ->assertJsonPath('data.transaction.gateway.code', 'gateway_2');

        $this->assertSame(TransactionStatus::PAID->value, $transaction->status);
        $this->assertSame('gateway_2', $transaction->gateway?->code);
        $this->assertCount(2, $transaction->attempts);
    }

    public function test_idempotent_purchase_replays_existing_transaction_against_mysql(): void
    {
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        Http::fake([
            '*/transacoes' => Http::response(['id' => 'ext-success'], 200),
        ]);

        $payload = [
            'client' => ['name' => 'Tester', 'email' => 'mysql.idempotency@test.local'],
            'payment' => ['card_number' => '5569000000006063', 'cvv' => '010'],
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $firstResponse = $this
            ->withHeader('Idempotency-Key', 'mysql-critical-checkout-1')
            ->postJson('/api/purchases', $payload);

        $secondResponse = $this
            ->withHeader('Idempotency-Key', 'mysql-critical-checkout-1')
            ->postJson('/api/purchases', $payload);

        $firstTransactionId = $firstResponse->json('data.transaction.id');

        $firstResponse->assertCreated()->assertJsonPath('data.replayed', false);
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.transaction.id', $firstTransactionId);

        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_duplicate_idempotency_key_returns_existing_transaction_during_creation_race_against_mysql(): void
    {
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        /** @var PaymentTransactionCreator $creator */
        $creator = app(PaymentTransactionCreator::class);

        $payload = [
            'name' => 'Tester',
            'email' => 'mysql.race@test.local',
            'card_number' => '5569000000006063',
            'cvv' => '010',
        ];
        $groupedItems = [$product->id => 1];
        $idempotencyKey = 'mysql-race-checkout-1';
        $idempotencyHash = hash('sha256', 'stable-payload');

        $firstTransaction = $creator->createProcessingTransaction($payload, $groupedItems, $idempotencyKey, $idempotencyHash);
        $secondTransaction = $creator->createProcessingTransaction($payload, $groupedItems, $idempotencyKey, $idempotencyHash);

        $this->assertSame($firstTransaction->id, $secondTransaction->id);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_finance_can_refund_paid_transaction_against_mysql(): void
    {
        $finance = User::query()->create([
            'name' => 'Finance',
            'email' => 'finance.mysql@test.local',
            'password' => 'password123',
            'role' => UserRole::FINANCE,
        ]);

        Sanctum::actingAs($finance);

        $gateway = Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);
        $client = Client::query()->create(['name' => 'Client', 'email' => 'client.mysql@test.local']);

        $transaction = Transaction::query()->create([
            'client_id' => $client->id,
            'gateway_id' => $gateway->id,
            'external_id' => 'external-123',
            'status' => TransactionStatus::PAID->value,
            'amount' => 1000,
            'card_last_numbers' => '6063',
        ]);

        Http::fake([
            '*/transacoes/reembolso' => Http::response(['id' => 'refund-1'], 200),
        ]);

        $response = $this->postJson('/api/refunds', ['transaction_id' => $transaction->id]);

        $response->assertOk()->assertJsonPath('data.refund.status', 'refunded');
        $this->assertSame(TransactionStatus::REFUNDED->value, $transaction->fresh()->status);
        $this->assertDatabaseHas('refunds', [
            'transaction_id' => $transaction->id,
            'status' => 'refunded',
        ]);
    }
}
