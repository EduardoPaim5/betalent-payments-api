<?php

namespace App\Enums;

enum RefundStatus: string
{
    case PROCESSING = 'processing';
    case REFUNDED = 'refunded';
    case REFUND_FAILED = 'refund_failed';
}
