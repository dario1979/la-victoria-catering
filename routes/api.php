<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductionOrderController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseReceiptController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('v1')->group(function (): void {
    Route::get('/auth/csrf', fn () => response()->json(['data' => ['token' => csrf_token()]]));
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth.login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth.forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth.reset-password');

    Route::middleware('auth')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'user']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware('tenant')->group(function (): void {
            Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
            Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show', 'update']);
            Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show', 'update']);
            Route::get('/locations', [LocationController::class, 'index']);
            Route::post('/locations', [LocationController::class, 'store']);
            Route::get('/lots', [LotController::class, 'index']);
            Route::get('/lots/{lot}', [LotController::class, 'show']);
            Route::post('/lots/adjustments', [LotController::class, 'adjust']);

            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::post('/suppliers/{supplier}/active', [SupplierController::class, 'setActive']);
            Route::get('/supplier-products', [SupplierProductController::class, 'index']);
            Route::post('/supplier-products', [SupplierProductController::class, 'store']);
            Route::get('/supplier-products/{supplierProduct}', [SupplierProductController::class, 'show']);
            Route::patch('/supplier-products/{supplierProduct}', [SupplierProductController::class, 'update']);
            Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
            Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
            Route::get('/purchase-orders/{purchaseOrder}/allowed-transitions', [PurchaseOrderController::class, 'allowedTransitions']);
            Route::post('/purchase-orders/{purchaseOrder}/transitions', [PurchaseOrderController::class, 'transition']);
            Route::get('/purchase-receipts', [PurchaseReceiptController::class, 'index']);
            Route::post('/purchase-orders/{purchaseOrder}/receipts', [PurchaseReceiptController::class, 'store']);
            Route::get('/purchase-receipts/{purchaseReceipt}', [PurchaseReceiptController::class, 'show']);

            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
            Route::get('/orders/{order}/allowed-transitions', [OrderController::class, 'allowedTransitions']);
            Route::post('/orders/{order}/transitions', [OrderController::class, 'transition']);
            Route::post('/orders/{order}/delivery', [DeliveryController::class, 'store']);

            Route::get('/production-orders', [ProductionOrderController::class, 'index']);
            Route::post('/production-orders', [ProductionOrderController::class, 'store']);
            Route::get('/production-orders/{productionOrder}', [ProductionOrderController::class, 'show']);
            Route::get('/production-orders/{productionOrder}/requirements', [ProductionOrderController::class, 'requirements']);
            Route::get('/production-orders/{productionOrder}/traceability', [ProductionOrderController::class, 'traceability']);
            Route::post('/production-orders/{productionOrder}/start', [ProductionOrderController::class, 'start']);

            Route::apiResource('recipes', RecipeController::class)->only(['index', 'store', 'show', 'update']);
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
