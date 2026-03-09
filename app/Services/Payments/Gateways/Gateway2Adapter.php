<?php

namespace App\Services\Payments\Gateways;

use App\Enums\GatewayErrorType;
use App\Models\Gateway;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class Gateway2Adapter implements PaymentGatewayPort
{
    public function supports(Gateway $gateway): bool
    {
        return $gateway->code === 'gateway_2';
    }

    public function authorizePayment(Gateway $gateway, array $payload): GatewayResult
    {
        $baseUrl = config('services.gateways.gateway_2.base_url');

        $response = Http::withHeaders($this->headers())
            ->post($baseUrl.'/transacoes', [
                'valor' => $payload['amount'],
                'nome' => $payload['name'],
                'email' => $payload['email'],
                'numeroCartao' => $payload['cardNumber'],
                'cvv' => $payload['cvv'],
            ]);

        $data = $response->json() ?: [];

        if ($response->successful()) {
            $externalId = $this->extractExternalId($data, $response)
                ?? $this->resolveExternalIdFromListing($baseUrl, $payload);

            if ($externalId === null) {
                return new GatewayResult(
                    false,
                    null,
                    'declined',
                    'Gateway 2 approved without external transaction id',
                    GatewayErrorType::TECHNICAL->value,
                    $data,
                    $response->status(),
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
        $baseUrl = config('services.gateways.gateway_2.base_url');

        $response = Http::withHeaders($this->headers())
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

    private function headers(): array
    {
        return [
            'Gateway-Auth-Token' => config('services.gateways.gateway_2.auth_token'),
            'Gateway-Auth-Secret' => config('services.gateways.gateway_2.auth_secret'),
        ];
    }

    private function resolveExternalIdFromListing(string $baseUrl, array $payload): ?string
    {
        $response = Http::withHeaders($this->headers())->get($baseUrl.'/transacoes');
        if (! $response->successful()) {
            return null;
        }

        foreach ($this->normalizeTransactions($response->json() ?: []) as $tx) {
            $amount = Arr::get($tx, 'valor', Arr::get($tx, 'amount'));
            $email = Arr::get($tx, 'email');
            $name = Arr::get($tx, 'nome', Arr::get($tx, 'name'));

            if ((int) $amount === (int) $payload['amount']
                && (string) $email === (string) $payload['email']
                && (string) $name === (string) $payload['name']) {
                return $this->extractExternalId($tx, $response);
            }
        }

        return null;
    }

    private function normalizeTransactions(array $data): array
    {
        $candidates = [
            $data,
            Arr::get($data, 'transacoes', []),
            Arr::get($data, 'transactions', []),
            Arr::get($data, 'data', []),
            Arr::get($data, 'items', []),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return array_reverse($candidate);
            }
        }

        return [];
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
