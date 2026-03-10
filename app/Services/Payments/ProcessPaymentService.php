<?php

namespace App\Services\Payments;

use App\Exceptions\GatewayClientException;
use App\Models\Gateway;
use App\Services\Payments\Gateways\GatewayResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class ProcessPaymentService
{
    public function __construct(
        private GatewayResolver $resolver,
        private PurchaseItemResolver $itemResolver,
        private PaymentIdempotencyService $idempotency,
        private PaymentTransactionCreator $transactionCreator,
        private GatewayAttemptRecorder $attemptRecorder,
        private PaymentTransactionStateManager $transactionStateManager,
    ) {}

    /**
     * @param  array<int, array{product_id:int, quantity:int}>  $items
     */
    public function execute(array $payload, array $items, ?string $idempotencyKey = null): PaymentExecutionResult
    {
        $groupedItems = $this->itemResolver->group($items);
        $idempotencyHash = $idempotencyKey !== null
            ? $this->idempotency->buildStableHash($payload, $groupedItems)
            : null;

        if ($idempotencyKey !== null) {
            $existingTransaction = $this->idempotency->findExistingTransaction($idempotencyKey, $idempotencyHash);
            if ($existingTransaction !== null) {
                return new PaymentExecutionResult($existingTransaction, true);
            }
        }

        $transaction = $this->transactionCreator->createProcessingTransaction(
            $payload,
            $groupedItems,
            $idempotencyKey,
            $idempotencyHash,
        );
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

            $adapter = $this->resolver->resolve($gateway);

            try {
                $result = $adapter->authorizePayment($gateway, [
                    'amount' => $transaction->amount,
                    'name' => $transaction->client->name,
                    'email' => $transaction->client->email,
                    'cardNumber' => $payload['card_number'],
                    'cvv' => $payload['cvv'],
                    'correlationId' => $transaction->correlation_id,
                ]);
            } catch (ConnectionException|GatewayClientException $exception) {
                $result = \App\Services\Payments\Gateways\GatewayResult::technicalFailure(
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
            $this->attemptRecorder->record($transaction, $gateway, $attemptOrder, $result, $latency);

            if ($result->success) {
                $paidTransaction = $this->transactionStateManager->markAsPaid($transaction, $gateway, $result);

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
                $failedTransaction = $this->transactionStateManager->markAsFailed($transaction, $lastError);

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

        $failedTransaction = $this->transactionStateManager->markAsFailed($transaction, $lastError ?? 'all gateways failed');

        Log::error('payment_processing_failed', [
            'transaction_id' => $failedTransaction->id,
            'failure_reason' => $failedTransaction->failure_reason,
        ]);

        return new PaymentExecutionResult($failedTransaction);
    }
}
