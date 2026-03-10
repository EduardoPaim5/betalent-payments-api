<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Refund\CreateRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Refund;
use App\Models\Transaction;
use App\Services\Payments\ProcessRefundService;
use App\Services\Support\ApiResponse;

class RefundController extends Controller
{
    public function __construct(private ProcessRefundService $service) {}

    public function store(CreateRefundRequest $request)
    {
        $this->authorize('create', Refund::class);

        $transaction = Transaction::query()->with('gateway')->findOrFail($request->validated('transaction_id'));
        $refund = $this->service->execute($transaction);

        return ApiResponse::success([
            'refund' => RefundResource::make($refund),
        ]);
    }
}
