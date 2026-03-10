<?php

namespace App\Services\Payments\Gateways;

use App\Enums\GatewayErrorType;
use App\Models\Gateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Gateway2Adapter implements PaymentGatewayPort
{
    public function supports(Gateway $gateway): bool
    {
        return $gateway->code === 'gateway_2';
    }

    public function authorizePayment(Gateway $gateway, array $payload): GatewayResult
    {
        $baseUrl = $this->baseUrl();

        $response = $this->httpClient()
            ->withHeaders($this->headers([
                'X-Correlation-ID' => (string) $payload['correlationId'],
            ]))
            ->post($baseUrl.'/transacoes', [
                'valor' => $payload['amount'],
                'nome' => $payload['name'],
                'email' => $payload['email'],
                'numeroCartao' => $payload['cardNumber'],
                'cvv' => $payload['cvv'],
            ]);

        $data = $response->json() ?: [];

        if ($response->successful()) {
            $externalId = $this->extractExternalId($data, $response);

            if ($externalId === null) {
                return GatewayResult::ambiguousFailure(
                    message: 'Gateway 2 approved payment without an external transaction id. Manual review is required.',
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
            (string) ($data['mensagem'] ?? $data['message'] ?? 'Gateway 2 authorization failed'),
            $response->status() >= 500 ? GatewayErrorType::TECHNICAL->value : GatewayErrorType::BUSINESS->value,
            $data,
            $response->status(),
        );
    }

    public function refund(Gateway $gateway, string $externalTransactionId): GatewayResult
    {
        $baseUrl = $this->baseUrl();

        $response = $this->httpClient()
            ->withHeaders($this->headers())
            ->post($baseUrl.'/transacoes/reembolso', [
                'id' => $externalTransactionId,
            ]);

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
            (string) ($data['mensagem'] ?? $data['message'] ?? 'Gateway 2 refund failed'),
            $response->status() >= 500 ? GatewayErrorType::TECHNICAL->value : GatewayErrorType::BUSINESS->value,
            $data,
            $response->status(),
        );
    }

    private function headers(array $extraHeaders = []): array
    {
        $token = (string) config('services.gateways.gateway_2.auth_token');
        $secret = (string) config('services.gateways.gateway_2.auth_secret');

        if ($token === '' || $secret === '') {
            throw new RuntimeException('Gateway 2 credentials are not configured.');
        }

        return array_merge([
            'Gateway-Auth-Token' => config('services.gateways.gateway_2.auth_token'),
            'Gateway-Auth-Secret' => config('services.gateways.gateway_2.auth_secret'),
        ], $extraHeaders);
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.gateways.gateway_2.base_url'), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Gateway 2 base URL is not configured.');
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
            'transacao_id',
            'transaction_id',
            'data.id',
            'transacao.id',
            'transaction.id',
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
