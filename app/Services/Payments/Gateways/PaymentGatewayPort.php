<?php

namespace App\Services\Payments\Gateways;

use App\Models\Gateway;

interface PaymentGatewayPort
{
    public function supports(Gateway $gateway): bool;

    public function authorizePayment(Gateway $gateway, array $payload): GatewayResult;

    public function refund(Gateway $gateway, string $externalTransactionId): GatewayResult;
}
