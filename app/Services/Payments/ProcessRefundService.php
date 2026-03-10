<?php

namespace App\Services\Payments;

use App\Enums\RefundStatus;
use App\Enums\TransactionStatus;
use App\Models\Refund;
use App\Models\Transaction;
use App\Services\Payments\Concerns\RedactsGatewayPayload;
use App\Services\Payments\Gateways\GatewayResolver;
use App\Services\Payments\Gateways\GatewayResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessRefundService
{
    use RedactsGatewayPayload;

    public function __construct(private GatewayResolver $resolver) {}

    public function execute(Transaction $transaction): Refund
    {
        [$lockedTransaction, $refund] = DB::transaction(function () use ($transaction): array {
            $lockedTransaction = Transaction::query()
                ->with(['gateway', 'refunds'])
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            if ($lockedTransaction->status !== TransactionStatus::PAID->value) {
                throw ValidationException::withMessages([
                    'transaction' => ['Only paid transactions can be refunded.'],
                ]);
            }

            if (! $lockedTransaction->gateway || ! $lockedTransaction->external_id) {
                throw ValidationException::withMessages([
                    'transaction' => ['Transaction does not contain gateway data for refund.'],
                ]);
            }

            $hasBlockingRefund = $lockedTransaction->refunds()
                ->whereIn('status', [RefundStatus::PROCESSING->value, RefundStatus::REFUNDED->value])
                ->exists();

            if ($hasBlockingRefund) {
                throw ValidationException::withMessages([
                    'transaction' => ['Transaction already has an in-flight or completed refund.'],
                ]);
            }

            $refund = $lockedTransaction->refunds()->create([
                'gateway_id' => $lockedTransaction->gateway_id,
                'status' => RefundStatus::PROCESSING->value,
            ]);

            $lockedTransaction->update([
                'status' => TransactionStatus::REFUND_PROCESSING->value,
            ]);

            return [$lockedTransaction, $refund];
        });

        Log::info('refund_processing_started', [
            'refund_id' => $refund->id,
            'transaction_id' => $lockedTransaction->id,
            'gateway_id' => $lockedTransaction->gateway_id,
        ]);

        try {
            $adapter = $this->resolver->resolve($lockedTransaction->gateway);
            $result = $adapter->refund($lockedTransaction->gateway, $lockedTransaction->external_id);
        } catch (Throwable $exception) {
            $result = GatewayResult::technicalFailure(
                message: 'Gateway refund request failed.',
                rawResponse: ['exception' => class_basename($exception)],
            );

            Log::error('refund_attempt_exception', [
                'refund_id' => $refund->id,
                'transaction_id' => $lockedTransaction->id,
                'gateway_id' => $lockedTransaction->gateway_id,
                'exception' => $exception::class,
            ]);
        }

        DB::transaction(function () use ($refund, $lockedTransaction, $result): void {
            $freshRefund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            $freshTransaction = Transaction::query()->lockForUpdate()->findOrFail($lockedTransaction->id);

            $freshRefund->update([
                'external_refund_id' => $result->externalId,
                'status' => $result->success ? RefundStatus::REFUNDED->value : RefundStatus::REFUND_FAILED->value,
                'message' => $result->message,
                'raw_response' => $this->redactGatewayPayload($result->rawResponse),
            ]);

            $freshTransaction->update([
                'status' => $result->success ? TransactionStatus::REFUNDED->value : TransactionStatus::PAID->value,
            ]);
        });

        $refund = Refund::query()->with(['gateway', 'transaction'])->findOrFail($refund->id);

        Log::info('refund_processing_finished', [
            'refund_id' => $refund->id,
            'transaction_id' => $lockedTransaction->id,
            'status' => $refund->status,
            'message' => $refund->message,
        ]);

        return $refund;
    }
}
