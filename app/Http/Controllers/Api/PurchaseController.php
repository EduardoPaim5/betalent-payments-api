<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\CreatePurchaseRequest;
use App\Http\Resources\TransactionResource;
use App\Services\Payments\ProcessPaymentService;
use App\Services\Support\ApiResponse;

class PurchaseController extends Controller
{
    public function __construct(private ProcessPaymentService $service)
    {
    }

    public function store(CreatePurchaseRequest $request)
    {
        $validated = $request->validated();

        $transaction = $this->service->execute([
            'name' => $validated['client']['name'],
            'email' => $validated['client']['email'],
            'card_number' => $validated['payment']['card_number'],
            'cvv' => $validated['payment']['cvv'],
        ], $validated['items']);

        if ($transaction->status === 'failed') {
            return ApiResponse::error('payment_failed', 'All gateways failed to process this payment.', [
                'transaction_id' => $transaction->id,
                'failure_reason' => $transaction->failure_reason,
            ], 422);
        }

        return ApiResponse::success([
            'transaction' => TransactionResource::make($transaction),
        ], 201);
    }
}
