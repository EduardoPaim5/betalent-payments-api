<?php

namespace App\Services\Payments;

use App\Enums\TransactionStatus;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\Payments\Concerns\RedactsGatewayPayload;
use App\Services\Payments\Gateways\GatewayResolver;
use App\Services\Payments\Gateways\GatewayResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessPaymentService
{
    use RedactsGatewayPayload;

    public function __construct(private GatewayResolver $resolver) {}

    /**
     * @param  array<int, array{product_id:int, quantity:int}>  $items
     */
    public function execute(array $payload, array $items, ?string $idempotencyKey = null): PaymentExecutionResult
    {
        $groupedItems = $this->groupItems($items);
        $idempotencyHash = $idempotencyKey !== null
            ? $this->buildIdempotencyHash($payload, $groupedItems)
            : null;

        if ($idempotencyKey !== null) {
            $existingTransaction = $this->findIdempotentTransaction($idempotencyKey, $idempotencyHash);
            if ($existingTransaction !== null) {
                return new PaymentExecutionResult($existingTransaction, true);
            }
        }

        $transaction = $this->createProcessingTransaction($payload, $groupedItems, $idempotencyKey, $idempotencyHash);
        $transaction->loadMissing('client');

        $gateways = Gateway::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        if ($gateways->isEmpty()) {
            $failedTransaction = $this->markTransactionAsFailed($transaction, 'No active gateways available.');

            Log::warning('payment_processing_failed_without_gateway', [
                'transaction_id' => $failedTransaction->id,
            ]);

            return new PaymentExecutionResult($failedTransaction);
        }

        $attemptOrder = 0;
        $lastError = null;

        foreach ($gateways as $gateway) {
            $attemptOrder++;
            $start = microtime(true);

            Log::info('payment_attempt_started', [
                'transaction_id' => $transaction->id,
                'gateway' => $gateway->code,
                'attempt_order' => $attemptOrder,
            ]);

            try {
                $adapter = $this->resolver->resolve($gateway);
                $result = $adapter->authorizePayment($gateway, [
                    'amount' => $transaction->amount,
                    'name' => $transaction->client->name,
                    'email' => $transaction->client->email,
                    'cardNumber' => $payload['card_number'],
                    'cvv' => $payload['cvv'],
                    'correlationId' => $transaction->correlation_id,
                ]);
            } catch (Throwable $exception) {
                $result = GatewayResult::technicalFailure(
                    message: 'Gateway request failed.',
                    rawResponse: ['exception' => class_basename($exception)],
                );

                Log::error('payment_attempt_exception', [
                    'transaction_id' => $transaction->id,
                    'gateway' => $gateway->code,
                    'attempt_order' => $attemptOrder,
                    'exception' => $exception::class,
                ]);
            }

            $latency = (int) ((microtime(true) - $start) * 1000);
            $this->recordAttempt($transaction, $gateway, $attemptOrder, $result, $latency);

            if ($result->success) {
                $paidTransaction = $this->markTransactionAsPaid($transaction, $gateway, $result);

                Log::info('payment_attempt_succeeded', [
                    'transaction_id' => $paidTransaction->id,
                    'gateway' => $gateway->code,
                    'attempt_order' => $attemptOrder,
                    'latency_ms' => $latency,
                ]);

                return new PaymentExecutionResult($paidTransaction);
            }

            $lastError = $result->message ?? 'Gateway authorization failed.';

            Log::warning('payment_attempt_failed', [
                'transaction_id' => $transaction->id,
                'gateway' => $gateway->code,
                'attempt_order' => $attemptOrder,
                'error_type' => $result->errorType,
                'message' => $result->message,
                'status_code' => $result->statusCode,
                'latency_ms' => $latency,
            ]);

            if ($result->shouldStopFallback) {
                $failedTransaction = $this->markTransactionAsFailed($transaction, $lastError);

                Log::error('payment_processing_stopped_without_fallback', [
                    'transaction_id' => $failedTransaction->id,
                    'gateway' => $gateway->code,
                    'attempt_order' => $attemptOrder,
                    'error_type' => $result->errorType,
                    'failure_reason' => $failedTransaction->failure_reason,
                ]);

                return new PaymentExecutionResult($failedTransaction);
            }
        }

        $failedTransaction = $this->markTransactionAsFailed($transaction, $lastError ?? 'all gateways failed');

        Log::error('payment_processing_failed', [
            'transaction_id' => $failedTransaction->id,
            'failure_reason' => $failedTransaction->failure_reason,
        ]);

        return new PaymentExecutionResult($failedTransaction);
    }

    /**
     * @param  array<int, array{product_id:int, quantity:int}>  $items
     * @return array<int, int>
     */
    private function groupItems(array $items): array
    {
        $groupedItems = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $groupedItems[$productId] = ($groupedItems[$productId] ?? 0) + (int) $item['quantity'];
        }

        ksort($groupedItems);

        return $groupedItems;
    }

    /**
     * @param  array<int, int>  $groupedItems
     * @return array<int, array{product:Product, quantity:int, unit_amount:int, line_total:int}>
     */
    private function resolveNormalizedItems(array $groupedItems): array
    {
        $products = Product::query()
            ->whereIn('id', array_keys($groupedItems))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($groupedItems)) {
            throw ValidationException::withMessages([
                'items' => ['One or more selected products are unavailable.'],
            ]);
        }

        $normalizedItems = [];

        foreach ($groupedItems as $productId => $quantity) {
            /** @var Product $product */
            $product = $products->get($productId);
            $lineTotal = $product->amount * $quantity;

            $normalizedItems[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_amount' => $product->amount,
                'line_total' => $lineTotal,
            ];
        }

        return $normalizedItems;
    }

    /**
     * @param  array<int, int>  $groupedItems
     */
    private function buildIdempotencyHash(array $payload, array $groupedItems): string
    {
        $cardFingerprint = hash_hmac(
            'sha256',
            preg_replace('/\D+/', '', (string) $payload['card_number']) ?: '',
            (string) (config('app.key') ?: 'betalent-payments'),
        );

        return hash('sha256', (string) json_encode([
            'client' => [
                'email' => (string) $payload['email'],
                'name' => (string) $payload['name'],
            ],
            'payment' => [
                'card_fingerprint' => $cardFingerprint,
            ],
            'items' => $groupedItems,
        ]));
    }

    private function findIdempotentTransaction(string $idempotencyKey, ?string $idempotencyHash): ?Transaction
    {
        $transaction = Transaction::query()
            ->with(['client', 'gateway', 'products'])
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($transaction === null) {
            return null;
        }

        if ($idempotencyHash !== null && $transaction->idempotency_hash !== null && $transaction->idempotency_hash !== $idempotencyHash) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['This idempotency key was already used with a different purchase payload.'],
            ]);
        }

        return $transaction;
    }

    /**
     * @param  array<int, int>  $groupedItems
     */
    private function createProcessingTransaction(
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

                if ($client->name !== $payload['name']) {
                    $client->update(['name' => $payload['name']]);
                }

                $normalizedItems = $this->resolveNormalizedItems($groupedItems);
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
            if ($idempotencyKey === null || ! $this->isDuplicateIdempotencyKeyException($exception)) {
                throw $exception;
            }

            $existingTransaction = $this->findIdempotentTransaction($idempotencyKey, $idempotencyHash);
            if ($existingTransaction !== null) {
                return $existingTransaction;
            }

            throw $exception;
        }
    }

    private function recordAttempt(
        Transaction $transaction,
        Gateway $gateway,
        int $attemptOrder,
        GatewayResult $result,
        int $latency,
    ): void {
        $transaction->attempts()->create([
            'gateway_id' => $gateway->id,
            'attempt_order' => $attemptOrder,
            'success' => $result->success,
            'error_type' => $result->errorType,
            'status_code' => $result->statusCode,
            'message' => $result->message,
            'external_id' => $result->externalId ?: null,
            'latency_ms' => $latency,
            'raw_response' => $this->redactGatewayPayload($result->rawResponse),
            'created_at' => now(),
        ]);
    }

    private function markTransactionAsPaid(Transaction $transaction, Gateway $gateway, GatewayResult $result): Transaction
    {
        $transaction->update([
            'gateway_id' => $gateway->id,
            'external_id' => $result->externalId ?: null,
            'status' => TransactionStatus::PAID->value,
            'failure_reason' => null,
        ]);

        return $transaction->fresh(['client', 'gateway', 'products']);
    }

    private function markTransactionAsFailed(Transaction $transaction, string $failureReason): Transaction
    {
        $transaction->update([
            'status' => TransactionStatus::FAILED->value,
            'failure_reason' => $failureReason,
        ]);

        return $transaction->fresh(['client', 'gateway', 'products']);
    }

    private function isDuplicateIdempotencyKeyException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'idempotency_key')
            && (
                str_contains($message, 'unique')
                || str_contains($message, 'duplicate')
                || str_contains($message, 'constraint failed')
            );
    }
}
