<?php

namespace App\Services\Payments;

use App\Enums\RefundStatus;
use App\Enums\TransactionStatus;
use App\Models\Refund;
use App\Models\Transaction;
use App\Services\Payments\Gateways\GatewayResolver;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class ProcessRefundService
{
    public function __construct(private GatewayResolver $resolver)
    {
    }

    public function execute(Transaction $transaction): Refund
    {
        if ($transaction->status !== TransactionStatus::PAID->value) {
            throw ValidationException::withMessages([
                'transaction' => ['Only paid transactions can be refunded.'],
            ]);
        }

        if (! $transaction->gateway || ! $transaction->external_id) {
            throw ValidationException::withMessages([
                'transaction' => ['Transaction does not contain gateway data for refund.'],
            ]);
        }

        $refund = $transaction->refunds()->create([
            'gateway_id' => $transaction->gateway_id,
            'status' => RefundStatus::PROCESSING->value,
        ]);

        Log::info('refund_processing_started', [
            'refund_id' => $refund->id,
            'transaction_id' => $transaction->id,
            'gateway_id' => $transaction->gateway_id,
            'external_id' => $transaction->external_id,
        ]);

        $adapter = $this->resolver->resolve($transaction->gateway);
        $result = $adapter->refund($transaction->gateway, $transaction->external_id);

        $refund->update([
            'external_refund_id' => $result->externalId,
            'status' => $result->success ? RefundStatus::REFUNDED->value : RefundStatus::REFUND_FAILED->value,
            'message' => $result->message,
            'raw_response' => $this->maskRawResponse($result->rawResponse),
        ]);

        $transaction->update([
            'status' => $result->success ? TransactionStatus::REFUNDED->value : TransactionStatus::REFUND_FAILED->value,
        ]);

        Log::info('refund_processing_finished', [
            'refund_id' => $refund->id,
            'transaction_id' => $transaction->id,
            'status' => $refund->status,
            'message' => $refund->message,
        ]);

        return $refund->fresh(['gateway', 'transaction']);
    }

    private function maskRawResponse(array $data): array
    {
        unset($data['cvv'], $data['cardNumber'], $data['numeroCartao']);

        return $data;
    }
}
