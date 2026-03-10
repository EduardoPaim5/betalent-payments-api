<?php

namespace App\Services\Payments;

use App\Models\Transaction;
use Illuminate\Database\QueryException;

class PaymentIdempotencyService
{
    /**
     * Builds a stable purchase fingerprint without CVV to avoid persisting sensitive auth data.
     *
     * @param  array<int, int>  $groupedItems
     */
    public function buildStableHash(array $payload, array $groupedItems): string
    {
        $cardFingerprint = hash_hmac(
            'sha256',
            preg_replace('/\D+/', '', (string) $payload['card_number']) ?: '',
            (string) (config('app.key') ?: 'betalent-payments'),
        );

        return hash('sha256', (string) json_encode([
            'client' => [
                'email' => (string) $payload['email'],
                'name' => (string) $payload['name'],
            ],
            'payment' => [
                'card_fingerprint' => $cardFingerprint,
            ],
            'items' => $groupedItems,
        ]));
    }

    public function findExistingTransaction(string $idempotencyKey, ?string $idempotencyHash): ?Transaction
    {
        $transaction = Transaction::query()
            ->with(['client', 'gateway', 'products'])
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($transaction === null) {
            return null;
        }

        if ($idempotencyHash !== null && $transaction->idempotency_hash !== null && $transaction->idempotency_hash !== $idempotencyHash) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'idempotency_key' => ['This idempotency key was already used with a different stable purchase payload.'],
            ]);
        }

        return $transaction;
    }

    public function isDuplicateIdempotencyKey(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'idempotency_key')
            && (
                str_contains($message, 'unique')
                || str_contains($message, 'duplicate')
                || str_contains($message, 'constraint failed')
            );
    }
}
