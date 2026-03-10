<?php

namespace App\Services\Payments;

use App\Enums\TransactionStatus;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentTransactionCreator
{
    public function __construct(
        private PurchaseItemResolver $itemResolver,
        private PaymentIdempotencyService $idempotency,
    ) {}

    /**
     * @param  array<int, int>  $groupedItems
     */
    public function createProcessingTransaction(
        array $payload,
        array $groupedItems,
        ?string $idempotencyKey,
        ?string $idempotencyHash,
    ): Transaction {
        try {
            return DB::transaction(function () use ($payload, $groupedItems, $idempotencyKey, $idempotencyHash): Transaction {
                $client = Client::query()->firstOrCreate(
                    ['email' => $payload['email']],
                    ['name' => $payload['name']],
                );

                $normalizedItems = $this->itemResolver->resolveNormalizedItems($groupedItems);
                $total = array_sum(array_column($normalizedItems, 'line_total'));

                $transaction = Transaction::query()->create([
                    'client_id' => $client->id,
                    'status' => TransactionStatus::PROCESSING->value,
                    'amount' => $total,
                    'card_last_numbers' => substr($payload['card_number'], -4),
                    'correlation_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_hash' => $idempotencyHash,
                ]);

                foreach ($normalizedItems as $item) {
                    $transaction->products()->attach($item['product']->id, [
                        'quantity' => $item['quantity'],
                        'unit_amount' => $item['unit_amount'],
                        'line_total' => $item['line_total'],
                    ]);
                }

                Log::info('payment_processing_started', [
                    'transaction_id' => $transaction->id,
                    'amount' => $total,
                    'items_count' => count($normalizedItems),
                    'idempotency_key_present' => $idempotencyKey !== null,
                ]);

                return $transaction->load(['client', 'products']);
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey === null || ! $this->idempotency->isDuplicateIdempotencyKey($exception)) {
                throw $exception;
            }

            $existingTransaction = $this->idempotency->findExistingTransaction($idempotencyKey, $idempotencyHash);
            if ($existingTransaction !== null) {
                return $existingTransaction;
            }

            throw $exception;
        }
    }
}
