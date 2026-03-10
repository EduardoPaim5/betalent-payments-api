<?php

namespace App\Services\Payments;

use App\Enums\TransactionStatus;
use App\Models\Gateway;
use App\Models\Transaction;
use App\Services\Payments\Gateways\GatewayResult;

class PaymentTransactionStateManager
{
    public function markAsPaid(Transaction $transaction, Gateway $gateway, GatewayResult $result): Transaction
    {
        $transaction->update([
            'gateway_id' => $gateway->id,
            'external_id' => $result->externalId ?: null,
            'status' => TransactionStatus::PAID->value,
            'failure_reason' => null,
        ]);

        return $transaction->fresh(['client', 'gateway', 'products']);
    }

    public function markAsFailed(Transaction $transaction, string $failureReason): Transaction
    {
        $transaction->update([
            'status' => TransactionStatus::FAILED->value,
            'failure_reason' => $failureReason,
        ]);

        return $transaction->fresh(['client', 'gateway', 'products']);
    }
}
