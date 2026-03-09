<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\Support\ApiResponse;

class TransactionController extends Controller
{
    public function index()
    {
        $transactions = Transaction::query()
            ->with(['client', 'gateway'])
            ->when(request('status'), function ($query, $status): void {
                $query->where('status', $status);
            })
            ->latest()
            ->paginate(request('per_page', 15));

        return ApiResponse::success(['transactions' => ApiResponse::paginated($transactions, TransactionResource::class)]);
    }

    public function show(Transaction $transaction)
    {
        $transaction->load(['client', 'gateway', 'products', 'attempts.gateway', 'refunds.gateway', 'refunds.transaction']);

        return ApiResponse::success(['transaction' => TransactionResource::make($transaction)]);
    }
}
