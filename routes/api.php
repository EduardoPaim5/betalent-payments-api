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

Route::post('/login', [AuthController::class, 'login']);
Route::post('/purchases', [PurchaseController::class, 'store']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::middleware('role:ADMIN,MANAGER,FINANCE')->group(function (): void {
        Route::get('/gateways', [GatewayController::class, 'index']);
        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/clients/{client}', [ClientController::class, 'show']);

        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
    });

    Route::middleware('role:ADMIN')->group(function (): void {
        Route::patch('/gateways/{gateway}/priority', [GatewayController::class, 'updatePriority']);
        Route::patch('/gateways/{gateway}/status', [GatewayController::class, 'toggle']);
    });

    Route::middleware('role:ADMIN,MANAGER')->group(function (): void {
        Route::apiResource('users', UserController::class);
    });

    Route::middleware('role:ADMIN,MANAGER,FINANCE')->group(function (): void {
        Route::apiResource('products', ProductController::class);
    });

    Route::middleware('role:ADMIN,FINANCE')->group(function (): void {
        Route::post('/refunds', [RefundController::class, 'store']);
    });
});
