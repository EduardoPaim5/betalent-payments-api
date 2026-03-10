<?php

namespace App\Services\Payments\Concerns;

trait RedactsGatewayPayload
{
    /**
     * Keys are normalized to lowercase alphanumerics before comparison.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'apikey',
        'authorization',
        'authtoken',
        'authsecret',
        'cardnumber',
        'cvc',
        'cvv',
        'gatewayauthtoken',
        'gatewayauthsecret',
        'numerocartao',
        'numerodocartao',
        'password',
        'secret',
        'securitycode',
        'senha',
        'token',
        'xapikey',
    ];

    private function redactGatewayPayload(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
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

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));

        if ($normalizedKey === '') {
            return false;
        }

        if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return str_contains($normalizedKey, 'cardnumber')
            || str_contains($normalizedKey, 'numerocartao');
    }
}
