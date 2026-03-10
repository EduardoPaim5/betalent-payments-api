<?php

namespace App\Services\Payments;

use App\Models\Transaction;

class TransactionCreationResult
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly bool $replayed = false,
    ) {}
}
