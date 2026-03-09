<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'gateway_id' => $this->gateway_id,
            'external_refund_id' => $this->external_refund_id,
            'status' => $this->status,
            'message' => $this->message,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'gateway' => GatewayResource::make($this->whenLoaded('gateway')),
            'transaction' => TransactionSummaryResource::make($this->whenLoaded('transaction')),
        ];
    }
}
