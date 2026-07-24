<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductionOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('v1')->group(function (): void {
    Route::get('/auth/csrf', fn () => response()->json(['data' => ['token' => csrf_token()]]));
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'user']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware('tenant')->group(function (): void {
            Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show', 'update']);
            Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show', 'update']);
            Route::get('/locations', [LocationController::class, 'index']);
            Route::post('/locations', [LocationController::class, 'store']);
            Route::get('/lots', [LotController::class, 'index']);
            Route::get('/lots/{lot}', [LotController::class, 'show']);
            Route::post('/lots/adjustments', [LotController::class, 'adjust']);

            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
            Route::get('/orders/{order}/allowed-transitions', [OrderController::class, 'allowedTransitions']);
            Route::post('/orders/{order}/transitions', [OrderController::class, 'transition']);
            Route::post('/orders/{order}/delivery', [DeliveryController::class, 'store']);

            Route::get('/production-orders', [ProductionOrderController::class, 'index']);
            Route::post('/production-orders', [ProductionOrderController::class, 'store']);
            Route::get('/production-orders/{productionOrder}', [ProductionOrderController::class, 'show']);
            Route::post('/production-orders/{productionOrder}/start', [ProductionOrderController::class, 'start']);
            Route::post('/production-orders/{productionOrder}/complete', [ProductionOrderController::class, 'complete']);

            Route::get('/payments', [PaymentController::class, 'index']);
            Route::post('/payments', [PaymentController::class, 'store']);
            Route::get('/payments/{payment}', [PaymentController::class, 'show']);

            Route::get('/alerts', [AlertController::class, 'index']);
            Route::get('/alerts/{alert}', [AlertController::class, 'show']);
            Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge']);
            Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve']);
        });
    });
});
