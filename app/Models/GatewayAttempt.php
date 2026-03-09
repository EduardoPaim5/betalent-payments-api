<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayAttempt extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'gateway_id',
        'attempt_order',
        'success',
        'error_type',
        'status_code',
        'message',
        'external_id',
        'latency_ms',
        'raw_response',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'bool',
            'status_code' => 'int',
            'latency_ms' => 'int',
            'raw_response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }
}
