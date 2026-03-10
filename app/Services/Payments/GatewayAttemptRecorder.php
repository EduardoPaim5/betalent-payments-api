<?php

namespace App\Services\Payments;

use App\Models\Gateway;
use App\Models\Transaction;
use App\Services\Payments\Concerns\RedactsGatewayPayload;
use App\Services\Payments\Gateways\GatewayResult;

class GatewayAttemptRecorder
{
    use RedactsGatewayPayload;

    public function record(
        Transaction $transaction,
        Gateway $gateway,
        int $attemptOrder,
        GatewayResult $result,
        int $latency,
    ): void {
        $transaction->attempts()->create([
            'gateway_id' => $gateway->id,
            'attempt_order' => $attemptOrder,
            'success' => $result->success,
            'error_type' => $result->errorType,
            'status_code' => $result->statusCode,
            'message' => $result->message,
            'external_id' => $result->externalId ?: null,
            'latency_ms' => $latency,
            'raw_response' => $this->redactGatewayPayload($result->rawResponse),
            'created_at' => now(),
        ]);
    }
}
