<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case PROCESSING = 'processing';
    case PAID = 'paid';
    case FAILED = 'failed';
    case REFUND_PROCESSING = 'refund_processing';
    case REFUNDED = 'refunded';
}
