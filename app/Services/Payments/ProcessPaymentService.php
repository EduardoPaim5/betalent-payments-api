<?php

namespace App\Services\Payments;

use App\Enums\TransactionStatus;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Transaction;
use App\Models\Product;
use App\Services\Payments\Gateways\GatewayResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPaymentService
{
    public function __construct(private GatewayResolver $resolver)
    {
    }

    /**
     * @param array<int, array{product_id:int, quantity:int}> $items
     */
    public function execute(array $payload, array $items): Transaction
    {
        return DB::transaction(function () use ($payload, $items): Transaction {
            $client = Client::query()->firstOrCreate(
                ['email' => $payload['email']],
                ['name' => $payload['name']],
            );

            if ($client->name !== $payload['name']) {
                $client->update(['name' => $payload['name']]);
            }

            $groupedItems = [];
            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $groupedItems[$productId] = ($groupedItems[$productId] ?? 0) + (int) $item['quantity'];
            }

            $total = 0;
            $normalizedItems = [];

            foreach ($groupedItems as $productId => $quantity) {
                $product = Product::query()->where('id', $productId)->where('is_active', true)->firstOrFail();
                $lineTotal = $product->amount * $quantity;
                $total += $lineTotal;

                $normalizedItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_amount' => $product->amount,
                    'line_total' => $lineTotal,
                ];
            }

            $transaction = Transaction::query()->create([
                'client_id' => $client->id,
                'status' => TransactionStatus::PROCESSING->value,
                'amount' => $total,
                'card_last_numbers' => substr($payload['card_number'], -4),
            ]);

            Log::info('payment_processing_started', [
                'transaction_id' => $transaction->id,
                'client_email' => $client->email,
                'amount' => $total,
                'items_count' => count($normalizedItems),
            ]);

            foreach ($normalizedItems as $item) {
                $transaction->products()->attach($item['product']->id, [
                    'quantity' => $item['quantity'],
                    'unit_amount' => $item['unit_amount'],
                    'line_total' => $item['line_total'],
                ]);
            }

            $gateways = Gateway::query()
                ->where('is_active', true)
                ->orderBy('priority')
                ->get();

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

                $adapter = $this->resolver->resolve($gateway);
                $result = $adapter->authorizePayment($gateway, [
                    'amount' => $total,
                    'name' => $client->name,
                    'email' => $client->email,
                    'cardNumber' => $payload['card_number'],
                    'cvv' => $payload['cvv'],
                ]);

                $latency = (int) ((microtime(true) - $start) * 1000);

                $transaction->attempts()->create([
                    'gateway_id' => $gateway->id,
                    'attempt_order' => $attemptOrder,
                    'success' => $result->success,
                    'error_type' => $result->errorType,
                    'status_code' => $result->statusCode,
                    'message' => $result->message,
                    'external_id' => $result->externalId ?: null,
                    'latency_ms' => $latency,
                    'raw_response' => $this->maskRawResponse($result->rawResponse),
                    'created_at' => now(),
                ]);

                if ($result->success) {
                    $transaction->update([
                        'gateway_id' => $gateway->id,
                        'external_id' => $result->externalId ?: null,
                        'status' => TransactionStatus::PAID->value,
                        'failure_reason' => null,
                    ]);

                    Log::info('payment_attempt_succeeded', [
                        'transaction_id' => $transaction->id,
                        'gateway' => $gateway->code,
                        'attempt_order' => $attemptOrder,
                        'external_id' => $result->externalId,
                        'latency_ms' => $latency,
                    ]);

                    return $transaction->fresh(['client', 'gateway', 'products']);
                }

                $lastError = $result->message;

                Log::warning('payment_attempt_failed', [
                    'transaction_id' => $transaction->id,
                    'gateway' => $gateway->code,
                    'attempt_order' => $attemptOrder,
                    'error_type' => $result->errorType,
                    'message' => $result->message,
                    'status_code' => $result->statusCode,
                    'latency_ms' => $latency,
                ]);
            }

            $transaction->update([
                'status' => TransactionStatus::FAILED->value,
                'failure_reason' => $lastError ?? 'all gateways failed',
            ]);

            Log::error('payment_processing_failed', [
                'transaction_id' => $transaction->id,
                'failure_reason' => $transaction->failure_reason,
            ]);

            return $transaction->fresh(['client', 'products', 'attempts']);
        });
    }

    private function maskRawResponse(array $data): array
    {
        unset($data['cvv'], $data['cardNumber'], $data['numeroCartao']);

        return $data;
    }
}
