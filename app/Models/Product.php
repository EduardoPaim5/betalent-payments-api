<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'amount',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'int',
            'is_active' => 'bool',
        ];
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'transaction_products')
            ->withPivot(['quantity', 'unit_amount', 'line_total'])
            ->withTimestamps();
    }
}
