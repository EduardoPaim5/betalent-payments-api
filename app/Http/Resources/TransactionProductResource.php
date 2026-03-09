<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'amount' => (int) $this->amount,
            'is_active' => (bool) $this->is_active,
            'quantity' => (int) $this->pivot?->quantity,
            'unit_amount' => (int) $this->pivot?->unit_amount,
            'line_total' => (int) $this->pivot?->line_total,
        ];
    }
}
