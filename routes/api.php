<?php

use App\Http\Controllers\Api\V1\AccountPayableController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashController;
use App\Http\Controllers\Api\V1\CustomerAccountController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OperationalStatusController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductionOrderController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseReceiptController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('v1')->group(function (): void {
    Route::get('/auth/csrf', fn () => response()->json(['data' => ['token' => csrf_token()]]));
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth.login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth.forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth.reset-password');
    Route::post('/webhooks/mercadopago', [IntegrationController::class, 'mercadoPagoWebhook'])
        ->middleware('throttle:integration.webhooks');

    Route::middleware('auth')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'user']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware('tenant')->group(function (): void {
            Route::get('/operations/status', [OperationalStatusController::class, 'show']);
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
            Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse']);

            Route::get('/cash-registers', [CashController::class, 'registers']);
            Route::post('/cash-registers', [CashController::class, 'storeRegister']);
            Route::post('/cash-registers/{cashRegister}/open', [CashController::class, 'open']);
            Route::get('/cash-sessions', [CashController::class, 'sessions']);
            Route::get('/cash-sessions/{cashSession}', [CashController::class, 'show']);
            Route::post('/cash-sessions/{cashSession}/movements', [CashController::class, 'movement']);
            Route::post('/cash-movements/{cashMovement}/reverse', [CashController::class, 'reverse']);
            Route::post('/cash-sessions/{cashSession}/close', [CashController::class, 'close']);
            Route::post('/cash-sessions/{cashSession}/approve-difference', [CashController::class, 'approveDifference']);

            Route::get('/customer-accounts', [CustomerAccountController::class, 'index']);
            Route::get('/customer-accounts/{customer}', [CustomerAccountController::class, 'summary']);
            Route::get('/customer-accounts/{customer}/entries', [CustomerAccountController::class, 'entries']);
            Route::post('/customer-accounts/{customer}/entries', [CustomerAccountController::class, 'store']);
            Route::post('/customer-account-entries/{customerAccountEntry}/reverse', [CustomerAccountController::class, 'reverse']);

            Route::get('/accounts-payable', [AccountPayableController::class, 'index']);
            Route::get('/accounts-payable/{accountPayable}', [AccountPayableController::class, 'show']);
            Route::post('/accounts-payable/{accountPayable}/payments', [AccountPayableController::class, 'pay']);
            Route::post('/accounts-payable-payments/{accountPayablePayment}/reverse', [AccountPayableController::class, 'reverse']);

            Route::get('/reconciliations', [ReconciliationController::class, 'index']);
            Route::post('/reconciliations', [ReconciliationController::class, 'store']);
            Route::post('/reconciliations/{reconciliation}/status', [ReconciliationController::class, 'resolve']);
            Route::get('/integration/payment-transactions', [IntegrationController::class, 'payments']);
            Route::post('/integration/payment-transactions/{paymentGatewayTransaction}/sync', [IntegrationController::class, 'syncPayment']);
            Route::get('/fiscal-documents', [IntegrationController::class, 'fiscalDocuments']);
            Route::post('/fiscal-documents', [IntegrationController::class, 'issueFiscal']);
            Route::post('/fiscal-documents/{fiscalDocument}/reprocess', [IntegrationController::class, 'reprocessFiscal']);
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notification-preferences', [NotificationController::class, 'preferences']);
            Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences']);
            Route::post('/push-subscriptions', [NotificationController::class, 'subscribe']);

            Route::get('/alerts', [AlertController::class, 'index']);
            Route::get('/alerts/{alert}', [AlertController::class, 'show']);
            Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge']);
            Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve']);
        });
    });
});
