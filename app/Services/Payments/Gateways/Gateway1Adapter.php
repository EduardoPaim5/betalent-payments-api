<?php

namespace App\Services\Payments\Gateways;

use App\Enums\GatewayErrorType;
use App\Models\Gateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Arr;

class Gateway1Adapter implements PaymentGatewayPort
{
    public function supports(Gateway $gateway): bool
    {
        return $gateway->code === 'gateway_1';
    }

    public function authorizePayment(Gateway $gateway, array $payload): GatewayResult
    {
        $baseUrl = config('services.gateways.gateway_1.base_url');
        $token = $this->login($baseUrl);

        $response = Http::withToken($token)
            ->post($baseUrl.'/transactions', [
                'amount' => $payload['amount'],
                'name' => $payload['name'],
                'email' => $payload['email'],
                'cardNumber' => $payload['cardNumber'],
                'cvv' => $payload['cvv'],
            ]);

        $data = $response->json() ?: [];

        if ($response->successful()) {
            $externalId = $this->extractExternalId($data, $response)
                ?? $this->resolveExternalIdFromListing($baseUrl, $token, $payload);

            if ($externalId === null) {
                return new GatewayResult(
                    false,
                    null,
                    'declined',
                    'Gateway 1 approved without external transaction id',
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
            (string) ($data['message'] ?? 'Gateway 1 authorization failed'),
            $response->status() >= 500 ? GatewayErrorType::TECHNICAL->value : GatewayErrorType::BUSINESS->value,
            $data,
            $response->status(),
        );
    }

    public function refund(Gateway $gateway, string $externalTransactionId): GatewayResult
    {
        $baseUrl = config('services.gateways.gateway_1.base_url');
        $token = $this->login($baseUrl);

        $response = Http::withToken($token)->post($baseUrl.'/transactions/'.$externalTransactionId.'/charge_back');
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
            (string) ($data['message'] ?? 'Gateway 1 refund failed'),
            $response->status() >= 500 ? GatewayErrorType::TECHNICAL->value : GatewayErrorType::BUSINESS->value,
            $data,
            $response->status(),
        );
    }

    private function login(string $baseUrl): string
    {
        $response = Http::post($baseUrl.'/login', [
            'email' => config('services.gateways.gateway_1.email'),
            'token' => config('services.gateways.gateway_1.token'),
        ]);

        return (string) ($response->json('token') ?? '');
    }

    private function resolveExternalIdFromListing(string $baseUrl, string $token, array $payload): ?string
    {
        $response = Http::withToken($token)->get($baseUrl.'/transactions');
        if (! $response->successful()) {
            return null;
        }

        foreach ($this->normalizeTransactions($response->json() ?: []) as $tx) {
            $amount = Arr::get($tx, 'amount');
            $email = Arr::get($tx, 'email');
            $name = Arr::get($tx, 'name');

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
