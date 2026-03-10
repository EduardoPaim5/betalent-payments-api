<?php

namespace Tests\Feature\Purchase;

use App\Models\Client;
use App\Models\Gateway;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\Payments\PaymentIdempotencyService;
use App\Services\Payments\PaymentTransactionCreator;
use App\Services\Payments\ProcessPaymentService;
use App\Services\Payments\TransactionCreationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class IdempotencyRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_idempotency_key_returns_existing_transaction_when_creation_races(): void
    {
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);

        /** @var PaymentTransactionCreator $creator */
        $creator = app(PaymentTransactionCreator::class);
        $payload = [
            'name' => 'Tester',
            'email' => 'tester@email.com',
            'card_number' => '5569000000006063',
            'cvv' => '010',
        ];
        $groupedItems = [$product->id => 1];
        $idempotencyKey = 'race-checkout-1';
        $idempotencyHash = hash('sha256', 'stable-payload');

        $firstTransaction = $creator->createProcessingTransaction($payload, $groupedItems, $idempotencyKey, $idempotencyHash);
        $secondTransaction = $creator->createProcessingTransaction($payload, $groupedItems, $idempotencyKey, $idempotencyHash);

        $this->assertFalse($firstTransaction->replayed);
        $this->assertTrue($secondTransaction->replayed);
        $this->assertSame($firstTransaction->transaction->id, $secondTransaction->transaction->id);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_replayed_transaction_from_creation_race_does_not_retry_gateway(): void
    {
        $product = Product::query()->create(['name' => 'Notebook', 'amount' => 1000, 'is_active' => true]);
        $client = Client::query()->create(['name' => 'Tester', 'email' => 'tester@email.com']);
        Gateway::query()->create(['code' => 'gateway_2', 'name' => 'Gateway 2', 'priority' => 1, 'is_active' => true]);

        $replayedTransaction = Transaction::query()->create([
            'client_id' => $client->id,
            'status' => 'processing',
            'amount' => 1000,
            'card_last_numbers' => '6063',
            'correlation_id' => '11111111-1111-1111-1111-111111111115',
            'idempotency_key' => 'race-checkout-2',
            'idempotency_hash' => 'stable-hash',
        ])->load('client');

        Http::fake();

        $this->mock(PaymentIdempotencyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('buildStableHash')->once()->andReturn('stable-hash');
            $mock->shouldReceive('findExistingTransaction')->once()->with('race-checkout-2', 'stable-hash')->andReturn(null);
        });

        $this->mock(PaymentTransactionCreator::class, function (MockInterface $mock) use ($replayedTransaction): void {
            $mock->shouldReceive('createProcessingTransaction')
                ->once()
                ->andReturn(new TransactionCreationResult($replayedTransaction, true));
        });

        $result = app(ProcessPaymentService::class)->execute([
            'name' => 'Tester',
            'email' => 'tester@email.com',
            'card_number' => '5569000000006063',
            'cvv' => '010',
        ], [
            ['product_id' => $product->id, 'quantity' => 1],
        ], 'race-checkout-2');

        $this->assertTrue($result->replayed);
        $this->assertSame($replayedTransaction->id, $result->transaction->id);
        $this->assertSame(202, $result->responseStatus());
        Http::assertNothingSent();
    }
}
