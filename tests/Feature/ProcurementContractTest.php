<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

class ProcurementContractTest extends TestCase
{
    public function test_procurement_routes_and_openapi_paths_do_not_drift(): void
    {
        $expected = [
            'GET /suppliers',
            'POST /suppliers',
            'GET /suppliers/{supplier}',
            'PATCH /suppliers/{supplier}',
            'POST /suppliers/{supplier}/active',
            'GET /supplier-products',
            'POST /supplier-products',
            'GET /supplier-products/{supplierProduct}',
            'PATCH /supplier-products/{supplierProduct}',
            'GET /purchase-orders',
            'POST /purchase-orders',
            'GET /purchase-orders/{purchaseOrder}',
            'PUT /purchase-orders/{purchaseOrder}',
            'GET /purchase-orders/{purchaseOrder}/allowed-transitions',
            'POST /purchase-orders/{purchaseOrder}/transitions',
            'POST /purchase-orders/{purchaseOrder}/receipts',
            'GET /purchase-receipts',
            'GET /purchase-receipts/{purchaseReceipt}',
        ];
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn (Route $route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => "{$method} /".str($route->uri())->after('api/v1/')))
            ->all();
        $contract = file_get_contents(base_path('docs/contracts/openapi.yaml'));
        self::assertIsString($contract);

        foreach ($expected as $operation) {
            self::assertContains($operation, $routes, "Laravel route missing: {$operation}");
            [, $path] = explode(' ', $operation, 2);
            self::assertStringContainsString("\n  {$path}:\n", $contract, "OpenAPI path missing: {$path}");
        }
        foreach ([
            'SupplierInput', 'SupplierProductInput', 'PurchaseOrderInput', 'PurchaseReceiptInput',
        ] as $schema) {
            self::assertStringContainsString("\n    {$schema}:\n", $contract);
        }
    }
}
