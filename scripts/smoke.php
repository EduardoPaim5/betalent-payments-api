<?php

declare(strict_types=1);

$baseUrl = rtrim((string) getenv('SMOKE_BASE_URL') ?: 'http://127.0.0.1:8000', '/');
$email = sprintf('smoke.%s@betalent.local', bin2hex(random_bytes(4)));
$idempotencyKey = 'smoke-checkout-'.bin2hex(random_bytes(6));

assertStatus(200, request('GET', $baseUrl.'/up'), 'health check');

$loginResponse = request('POST', $baseUrl.'/api/login', [
    'email' => 'admin@betalent.local',
    'password' => 'password123',
], [
    'Content-Type: application/json',
    'Accept: application/json',
]);
assertStatus(200, $loginResponse, 'admin login');

$token = $loginResponse['body']['data']['token'] ?? null;
if (! is_string($token) || $token === '') {
    fail('admin login did not return a bearer token');
}

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer '.$token,
];

$productsResponse = request('GET', $baseUrl.'/api/products?per_page=1', null, $headers);
assertStatus(200, $productsResponse, 'product listing');

$productId = $productsResponse['body']['data']['products']['data'][0]['id'] ?? null;
if (! is_int($productId)) {
    fail('product listing did not return a seed product id');
}

$purchasePayload = [
    'client' => [
        'name' => 'Smoke Tester',
        'email' => $email,
    ],
    'payment' => [
        'card_number' => '5569000000006063',
        'cvv' => '100',
    ],
    'items' => [
        [
            'product_id' => $productId,
            'quantity' => 1,
        ],
    ],
];

$purchaseResponse = request('POST', $baseUrl.'/api/purchases', $purchasePayload, [
    ...$headers,
    'Idempotency-Key: '.$idempotencyKey,
]);
assertStatus(201, $purchaseResponse, 'purchase with fallback');
assertSame('paid', $purchaseResponse['body']['data']['transaction']['status'] ?? null, 'purchase status');
assertSame('gateway_2', $purchaseResponse['body']['data']['transaction']['gateway']['code'] ?? null, 'gateway fallback winner');

$transactionId = $purchaseResponse['body']['data']['transaction']['id'] ?? null;
if (! is_string($transactionId) || $transactionId === '') {
    fail('purchase did not return a transaction id');
}

$replayResponse = request('POST', $baseUrl.'/api/purchases', $purchasePayload, [
    ...$headers,
    'Idempotency-Key: '.$idempotencyKey,
]);
assertStatus(200, $replayResponse, 'idempotent purchase replay');
assertSame(true, $replayResponse['body']['data']['replayed'] ?? null, 'replayed purchase flag');
assertSame($transactionId, $replayResponse['body']['data']['transaction']['id'] ?? null, 'replayed transaction id');

$transactionResponse = request('GET', $baseUrl.'/api/transactions/'.$transactionId, null, $headers);
assertStatus(200, $transactionResponse, 'transaction detail');
assertSame(2, count($transactionResponse['body']['data']['transaction']['attempts'] ?? []), 'gateway attempt count');

$refundResponse = request('POST', $baseUrl.'/api/refunds', [
    'transaction_id' => $transactionId,
], $headers);
assertStatus(200, $refundResponse, 'refund');
assertSame('refunded', $refundResponse['body']['data']['refund']['status'] ?? null, 'refund status');

$secondRefundResponse = request('POST', $baseUrl.'/api/refunds', [
    'transaction_id' => $transactionId,
], $headers);
assertStatus(422, $secondRefundResponse, 'duplicate refund rejection');

fwrite(STDOUT, "Smoke test passed.\n");

/**
 * @param  array<int, string>  $headers
 * @return array{status:int, body:array<string, mixed>, raw:string}
 */
function request(string $method, string $url, ?array $payload = null, array $headers = []): array
{
    $contextHeaders = implode("\r\n", $headers);
    if ($contextHeaders !== '') {
        $contextHeaders .= "\r\n";
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => $contextHeaders,
            'ignore_errors' => true,
        ],
    ];

    if ($payload !== null) {
        $options['http']['content'] = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    $context = stream_context_create($options);
    $raw = @file_get_contents($url, false, $context);

    if ($raw === false) {
        fail(sprintf('request failed: %s %s', $method, $url));
    }

    $statusLine = $http_response_header[0] ?? '';
    preg_match('/HTTP\/\S+\s+(\d{3})/', $statusLine, $matches);
    $status = (int) ($matches[1] ?? 0);

    /** @var array<string, mixed> $body */
    $body = json_decode($raw, true) ?? [];

    return [
        'status' => $status,
        'body' => $body,
        'raw' => $raw,
    ];
}

/**
 * @param  array{status:int, body:array<string, mixed>, raw:string}  $response
 */
function assertStatus(int $expected, array $response, string $label): void
{
    if ($response['status'] !== $expected) {
        fail(sprintf(
            '%s expected HTTP %d, got %d. Body: %s',
            $label,
            $expected,
            $response['status'],
            $response['raw'],
        ));
    }
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fail(sprintf(
            '%s expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}
