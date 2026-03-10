<?php

namespace Tests\Feature\Purchase;

use App\Models\Product;
use App\Models\Transaction;
use App\Services\Payments\PaymentTransactionCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertSame($firstTransaction->id, $secondTransaction->id);
        $this->assertSame(1, Transaction::query()->count());
    }
}
