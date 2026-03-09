<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'gateway_id' => $this->gateway_id,
            'external_id' => $this->external_id,
            'status' => $this->status,
            'amount' => (int) $this->amount,
            'card_last_numbers' => $this->card_last_numbers,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'client' => ClientResource::make($this->whenLoaded('client')),
            'gateway' => GatewayResource::make($this->whenLoaded('gateway')),
            'products' => TransactionProductResource::collection($this->whenLoaded('products')),
            'attempts' => GatewayAttemptResource::collection($this->whenLoaded('attempts')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
        ];
    }
}
