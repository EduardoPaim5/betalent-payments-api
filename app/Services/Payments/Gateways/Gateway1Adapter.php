<?php

namespace App\Services\Payments\Gateways;

use App\Enums\GatewayErrorType;
use App\Models\Gateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Gateway1Adapter implements PaymentGatewayPort
{
    public function supports(Gateway $gateway): bool
    {
        return $gateway->code === 'gateway_1';
    }

    public function authorizePayment(Gateway $gateway, array $payload): GatewayResult
    {
        $baseUrl = $this->baseUrl();
        $token = $this->login($baseUrl);

        $response = $this->httpClient()
            ->withToken($token)
            ->withHeaders([
                'X-Correlation-ID' => (string) $payload['correlationId'],
            ])
            ->post($baseUrl.'/transactions', [
                'amount' => $payload['amount'],
                'name' => $payload['name'],
                'email' => $payload['email'],
                'cardNumber' => $payload['cardNumber'],
                'cvv' => $payload['cvv'],
            ]);

        $data = $response->json() ?: [];

        if ($response->successful()) {
            $externalId = $this->extractExternalId($data, $response);

            if ($externalId === null) {
                return GatewayResult::ambiguousFailure(
                    message: 'Gateway 1 approved payment without an external transaction id. Manual review is required.',
                    rawResponse: $data,
                    statusCode: $response->status(),
                );
            }

            return new GatewayResult(
                true,
                $externalId,
                'approved',
                'Payment approved',
                null,
                $data,
                $response->status(),
            );
        }

        return new GatewayResult(
            false,
            null,
            'declined',
            (string) ($data['message'] ?? $data['error'] ?? 'Gateway 1 authorization failed'),
            $response->status() >= 500 ? GatewayErrorType::TECHNICAL->value : GatewayErrorType::BUSINESS->value,
            $data,
            $response->status(),
        );
    }

    public function refund(Gateway $gateway, string $externalTransactionId): GatewayResult
    {
        $baseUrl = $this->baseUrl();
        $token = $this->login($baseUrl);

        $response = $this->httpClient()
            ->withToken($token)
            ->post($baseUrl.'/transactions/'.$externalTransactionId.'/charge_back');
        $data = $response->json() ?: [];

        if ($response->successful()) {
            return new GatewayResult(
                true,
                $this->extractExternalId($data, $response) ?? $externalTransactionId,
                'refunded',
                'Refund processed',
                null,
                $data,
                $response->status(),
            );
        }

        return new GatewayResult(
            false,
            null,
            'refund_failed',
            (string) ($data['message'] ?? $data['error'] ?? 'Gateway 1 refund failed'),
            $response->status() >= 500 ? GatewayErrorType::TECHNICAL->value : GatewayErrorType::BUSINESS->value,
            $data,
            $response->status(),
        );
    }

    private function login(string $baseUrl): string
    {
        $email = (string) config('services.gateways.gateway_1.email');
        $token = (string) config('services.gateways.gateway_1.token');

        if ($email === '' || $token === '') {
            throw new RuntimeException('Gateway 1 credentials are not configured.');
        }

        $response = $this->httpClient()->post($baseUrl.'/login', [
            'email' => $email,
            'token' => $token,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gateway 1 authentication failed.');
        }

        $token = (string) ($response->json('token') ?? '');
        if ($token === '') {
            throw new RuntimeException('Gateway 1 authentication token is missing.');
        }

        return $token;
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.gateways.gateway_1.base_url'), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Gateway 1 base URL is not configured.');
        }

        return $baseUrl;
    }

    private function httpClient(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->connectTimeout(2)
            ->timeout(5);
    }

    private function extractExternalId(array $data, \Illuminate\Http\Client\Response $response): ?string
    {
        $candidates = [
            'id',
            'external_id',
            'externalId',
            'transaction_id',
            'data.id',
            'transaction.id',
            'transacao.id',
        ];

        foreach ($candidates as $path) {
            $value = Arr::get($data, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        foreach (['x-transaction-id', 'transaction-id', 'x-id', 'x-resource-id'] as $header) {
            $value = $response->header($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $location = $response->header('location');
        if (is_string($location) && $location !== '') {
            $segments = explode('/', trim($location));
            $last = trim((string) end($segments));
            if ($last !== '') {
                return $last;
            }
        }

        return null;
    }
}
