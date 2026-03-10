<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\GatewayController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('throttle:purchases');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/gateways', [GatewayController::class, 'index']);
    Route::patch('/gateways/{gateway}/priority', [GatewayController::class, 'updatePriority']);
    Route::patch('/gateways/{gateway}/status', [GatewayController::class, 'toggle']);

    Route::get('/clients', [ClientController::class, 'index']);
    Route::get('/clients/{client}', [ClientController::class, 'show']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);

    Route::apiResource('users', UserController::class);
    Route::apiResource('products', ProductController::class);

    Route::post('/refunds', [RefundController::class, 'store']);
});
