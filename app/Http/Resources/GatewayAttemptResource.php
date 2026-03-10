<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GatewayAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gateway_id' => $this->gateway_id,
            'gateway' => GatewayResource::make($this->whenLoaded('gateway')),
            'attempt_order' => (int) $this->attempt_order,
            'success' => (bool) $this->success,
            'error_type' => $this->error_type,
            'status_code' => $this->status_code,
            'message' => $this->message,
            'latency_ms' => $this->latency_ms,
            'created_at' => $this->created_at,
        ];
    }
}
