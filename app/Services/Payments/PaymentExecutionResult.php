<?php

namespace App\Services\Payments;

use App\Enums\TransactionStatus;
use App\Models\Transaction;

class PaymentExecutionResult
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly bool $replayed = false,
    ) {}

    public function responseStatus(): int
    {
        if ($this->transaction->status === TransactionStatus::PROCESSING->value) {
            return 202;
        }

        return $this->replayed ? 200 : 201;
    }
}
