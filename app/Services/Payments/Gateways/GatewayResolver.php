<?php

namespace App\Services\Payments\Gateways;

use App\Models\Gateway;
use RuntimeException;

class GatewayResolver
{
    /**
     * @param  array<int, PaymentGatewayPort>  $adapters
     */
    public function __construct(private array $adapters) {}

    public function resolve(Gateway $gateway): PaymentGatewayPort
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($gateway)) {
                return $adapter;
            }
        }

        throw new RuntimeException('Gateway adapter not found for '.$gateway->code);
    }
}
