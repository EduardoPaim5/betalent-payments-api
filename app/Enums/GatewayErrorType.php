<?php

namespace App\Enums;

enum GatewayErrorType: string
{
    case TECHNICAL = 'technical_error';
    case BUSINESS = 'business_error';
    case AMBIGUOUS = 'ambiguous_response';
}
