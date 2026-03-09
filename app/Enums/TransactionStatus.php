<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case PROCESSING = 'processing';
    case PAID = 'paid';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
    case REFUND_FAILED = 'refund_failed';
}
