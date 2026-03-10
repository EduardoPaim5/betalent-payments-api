<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gateway\ToggleGatewayStatusRequest;
use App\Http\Requests\Gateway\UpdateGatewayPriorityRequest;
use App\Http\Resources\GatewayResource;
use App\Models\Gateway;
use App\Services\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GatewayController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'gateways' => GatewayResource::collection(Gateway::query()->orderBy('priority')->get()),
        ]);
    }

    public function toggle(ToggleGatewayStatusRequest $request, Gateway $gateway)
    {
        $gateway->update($request->validated());

        Log::info('gateway_status_updated', [
            'gateway_id' => $gateway->id,
            'gateway' => $gateway->code,
            'is_active' => $gateway->is_active,
            'actor_id' => $request->user()?->id,
        ]);

        return ApiResponse::success(['gateway' => GatewayResource::make($gateway->fresh())]);
    }

    public function updatePriority(UpdateGatewayPriorityRequest $request, Gateway $gateway)
    {
        DB::transaction(function () use ($gateway, $request): void {
            $newPriority = (int) $request->validated('priority');

            $gateways = Gateway::query()->orderBy('priority')->lockForUpdate()->get();
            $withoutCurrent = $gateways->where('id', '!=', $gateway->id)->values();
            $newPriority = max(1, min($newPriority, $withoutCurrent->count() + 1));

            $reordered = $withoutCurrent->slice(0, $newPriority - 1)
                ->push($gateway)
                ->merge($withoutCurrent->slice($newPriority - 1))
                ->values();

            $temporaryOffset = $reordered->count() + 100;

            foreach ($reordered as $index => $item) {
                $item->update(['priority' => $temporaryOffset + $index]);
            }

            foreach ($reordered as $index => $item) {
                $item->update(['priority' => $index + 1]);
            }
        });

        Log::info('gateway_priority_updated', [
            'gateway_id' => $gateway->id,
            'gateway' => $gateway->code,
            'priority' => $gateway->fresh()->priority,
            'actor_id' => $request->user()?->id,
        ]);

        return ApiResponse::success(['gateway' => GatewayResource::make($gateway->fresh())]);
    }
}
