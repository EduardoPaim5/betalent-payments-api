<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\Concerns\RedactsGatewayPayload;
use PHPUnit\Framework\TestCase;

class RedactsGatewayPayloadTest extends TestCase
{
    public function test_sensitive_gateway_fields_are_removed_recursively(): void
    {
        $redactor = new class
        {
            use RedactsGatewayPayload;

            public function redact(array $payload): array
            {
                return $this->redactGatewayPayload($payload);
            }
        };

        $redacted = $redactor->redact([
            'numeroCartao' => '5569000000006063',
            'cardNumber' => '4111000000006063',
            'card_number' => '4111000000006063',
            'cvv' => '100',
            'Gateway-Auth-Token' => 'token-123',
            'Gateway_Auth_Secret' => 'secret-456',
            'Authorization' => 'Bearer token-123',
            'nested' => [
                'auth.token' => 'secret-123',
                'numero_cartao' => '1234567890123456',
                'safe' => 'value',
            ],
        ]);

        $this->assertSame([
            'nested' => [
                'safe' => 'value',
            ],
        ], $redacted);
    }
}
