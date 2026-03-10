<?php

namespace App\Services\Payments\Concerns;

trait RedactsGatewayPayload
{
    private function redactGatewayPayload(array $payload): array
    {
        $sensitiveKeys = [
            'cardnumber',
            'numeroCartao',
            'numero_cartao',
            'cvv',
            'token',
            'auth_token',
            'auth_secret',
        ];

        $redacted = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key) ? strtolower($key) : $key;

            if (is_string($normalizedKey) && in_array($normalizedKey, $sensitiveKeys, true)) {
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactGatewayPayload($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }
}
