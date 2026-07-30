<?php

namespace Tests\Feature;

use App\Domain\Alerts\AlertManager;
use App\Jobs\DetectInventoryRisks;
use App\Jobs\DetectPurchaseRisks;
use App\Models\StockMovement;
use App\Models\SupplierProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_receipts_are_partial_idempotent_and_stock_traceable(): void
    {
        [$organization, $branch, $user] = $this->tenant('admin');
        $this->actingAs($user)->withHeaders($this->headers($organization, $branch));
        [$supplier, $catalog] = $this->supplierAndCatalog($organization);

        $order = $this->withHeader('Idempotency-Key', 'purchase-create-1')
            ->postJson('/api/v1/purchase-orders', [
                'supplier_id' => $supplier['id'],
                'ordered_at' => today()->toDateString(),
                'expected_at' => today()->addDays(2)->toDateString(),
                'currency' => 'ARS',
                'items' => [[
                    'supplier_product_id' => $catalog['id'],
                    'quantity' => '1.500',
                ]],
            ])->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.status', 'draft')
            ->json('data');
        $orderItem = $order['items'][0];

        $this->withHeader('Idempotency-Key', 'purchase-approve-1')
            ->postJson("/api/v1/purchase-orders/{$order['id']}/transitions", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->putJson("/api/v1/purchase-orders/{$order['id']}", [
            'supplier_id' => $supplier['id'], 'ordered_at' => today()->toDateString(),
            'currency' => 'ARS', 'items' => [[
                'supplier_product_id' => $catalog['id'], 'quantity' => '2.000',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->withHeader('Idempotency-Key', 'purchase-send-1')
            ->postJson("/api/v1/purchase-orders/{$order['id']}/transitions", ['status' => 'sent'])
            ->assertOk()->assertJsonPath('data.status', 'sent');

        $location = DB::table('locations')->insertGetId([
            'organization_id' => $organization, 'branch_id' => $branch,
            'name' => 'Depósito compras', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $partialPayload = [
            'received_at' => now()->subMinute()->toISOString(),
            'items' => [[
                'purchase_order_item_id' => $orderItem['id'], 'location_id' => $location,
                'received_quantity' => '0.600', 'accepted_quantity' => '0.500',
                'rejected_quantity' => '0.100', 'discrepancy_type' => 'damaged',
                'discrepancy_reason' => 'Bolsa húmeda', 'lot_code' => 'HARINA-001',
                'expires_at' => today()->addMonths(3)->toDateString(),
            ]],
        ];
        $receipt = $this->withHeader('Idempotency-Key', 'purchase-receipt-1')
            ->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", $partialPayload)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'false')
            ->json('data');
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'partially_received']);
        $this->assertDatabaseHas('inventory_lots', [
            'organization_id' => $organization, 'branch_id' => $branch,
            'code' => 'HARINA-001', 'unit' => 'g', 'quantity' => 500,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'organization_id' => $organization, 'branch_id' => $branch,
            'reference_id' => $receipt['id'], 'type' => 'purchase_receipt', 'quantity' => 500,
        ]);
        $this->assertDatabaseHas('alerts', [
            'organization_id' => $organization, 'event' => 'PurchaseReceiptDiscrepancy',
            'recipient' => 'purchasing', 'status' => 'open',
        ]);

        $this->withHeader('Idempotency-Key', 'purchase-receipt-1')
            ->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", $partialPayload)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $receipt['id']);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $orderItem['id'], 'received_quantity' => 0.5,
        ]);

        $this->withHeader('Idempotency-Key', 'purchase-receipt-2')
            ->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", [
                'received_at' => now()->subMinute()->toISOString(),
                'items' => [[
                    'purchase_order_item_id' => $orderItem['id'], 'location_id' => $location,
                    'received_quantity' => '1.000', 'accepted_quantity' => '1.000',
                    'rejected_quantity' => '0.000', 'lot_code' => 'HARINA-001',
                    'expires_at' => today()->addMonths(3)->toDateString(),
                ]],
            ])->assertCreated();
        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $orderItem['id'], 'quantity' => 1.5, 'received_quantity' => 1.5,
        ]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'status' => 'received']);
        $this->assertDatabaseHas('inventory_lots', ['code' => 'HARINA-001', 'quantity' => 1500]);
        $this->assertDatabaseHas('alerts', [
            'organization_id' => $organization,
            'deduplication_key' => "purchase-order:{$order['id']}:partial",
            'status' => 'resolved',
        ]);
        $this->expectException(\LogicException::class);
        StockMovement::query()->firstOrFail()->update(['reason' => 'tampered']);
    }

    public function test_overreceipt_is_rejected_without_stock_and_raises_an_alert(): void
    {
        [$organization, $branch, $user] = $this->tenant('purchasing');
        $this->actingAs($user)->withHeaders($this->headers($organization, $branch));
        [$supplier, $catalog] = $this->supplierAndCatalog($organization);
        $order = $this->createSentOrder($supplier['id'], $catalog['id']);
        $location = DB::table('locations')->insertGetId([
            'organization_id' => $organization, 'branch_id' => $branch,
            'name' => 'Recepción', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Idempotency-Key', 'overreceipt')
            ->postJson("/api/v1/purchase-orders/{$order['id']}/receipts", [
                'received_at' => now()->subMinute()->toISOString(),
                'items' => [[
                    'purchase_order_item_id' => $order['items'][0]['id'], 'location_id' => $location,
                    'received_quantity' => '1.001', 'accepted_quantity' => '1.001',
                    'rejected_quantity' => '0.000', 'lot_code' => 'OVER-001',
                    'expires_at' => today()->addMonth()->toDateString(),
                ]],
            ])->assertUnprocessable()->assertJsonValidationErrors('items.0.accepted_quantity');
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('alerts', [
            'organization_id' => $organization,
            'deduplication_key' => "purchase-order:{$order['id']}:receipt-failed",
            'status' => 'open',
        ]);
    }

    public function test_procurement_permissions_and_tenant_boundaries_are_enforced(): void
    {
        [$organizationA, $branchA, $purchasing] = $this->tenant('purchasing');
        [$organizationB, $branchB] = $this->tenant('admin');
        $supplierB = DB::table('suppliers')->insertGetId([
            'organization_id' => $organizationB, 'trade_name' => 'Proveedor B',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($purchasing)->withHeaders($this->headers($organizationA, $branchA))
            ->getJson("/api/v1/suppliers/{$supplierB}")->assertNotFound();

        [, , $sales] = $this->tenant('sales');
        $salesTenant = DB::table('organization_user')->where('user_id', $sales->id)->first();
        $salesBranch = DB::table('branch_user')->where('user_id', $sales->id)->value('branch_id');
        $this->actingAs($sales)->withHeaders($this->headers($salesTenant->organization_id, $salesBranch))
            ->getJson('/api/v1/suppliers')->assertForbidden();

        [, , $production] = $this->tenant('production');
        $productionTenant = DB::table('organization_user')->where('user_id', $production->id)->first();
        $productionBranch = DB::table('branch_user')->where('user_id', $production->id)->value('branch_id');
        $headers = $this->headers($productionTenant->organization_id, $productionBranch);
        $this->actingAs($production)->withHeaders($headers)->getJson('/api/v1/purchase-orders')->assertOk();
        $this->actingAs($production)->withHeaders($headers)
            ->postJson('/api/v1/suppliers', [])->assertForbidden();

        self::assertNotSame($organizationA, $organizationB);
        self::assertNotSame($branchA, $branchB);
    }

    public function test_supplier_price_history_and_purchase_exports_are_available(): void
    {
        [$organization, $branch, $user] = $this->tenant('admin');
        $this->actingAs($user)->withHeaders($this->headers($organization, $branch));
        [$supplier, $catalog] = $this->supplierAndCatalog($organization);
        $this->patchJson("/api/v1/supplier-products/{$catalog['id']}", [
            'supplier_id' => $supplier['id'], 'product_id' => $catalog['product_id'],
            'purchase_unit' => 'kg', 'conversion_factor' => '1000.000000',
            'minimum_quantity' => '2.000', 'lead_time_days' => 3,
            'preferred' => true, 'active' => true, 'price' => '2000.00',
            'currency' => 'ARS', 'price_valid_from' => today()->toDateString(),
        ])->assertOk()->assertJsonCount(1, 'data.prices');
        $this->patchJson("/api/v1/supplier-products/{$catalog['id']}", [
            'supplier_id' => $supplier['id'], 'product_id' => $catalog['product_id'],
            'purchase_unit' => 'kg', 'conversion_factor' => '1000.000000',
            'minimum_quantity' => '1.000', 'lead_time_days' => 2,
            'preferred' => true, 'active' => true, 'price' => '2200.00',
            'currency' => 'ARS', 'price_valid_from' => today()->addDay()->toDateString(),
        ])->assertOk()->assertJsonCount(2, 'data.prices');
        $previousPrice = SupplierProductPrice::query()
            ->where('supplier_product_id', $catalog['id'])->oldest('id')->firstOrFail();
        self::assertSame(today()->toDateString(), $previousPrice->valid_until->toDateString());
        $this->createSentOrder($supplier['id'], $catalog['id']);
        $this->get('/api/v1/purchase-orders?export=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_risk_detectors_connect_low_stock_suppliers_orders_and_alert_closure(): void
    {
        [$organization, $branch, $user] = $this->tenant('admin');
        $this->actingAs($user)->withHeaders($this->headers($organization, $branch));
        [$supplier, $catalog] = $this->supplierAndCatalog($organization);
        $productId = $catalog['product_id'];

        app(DetectInventoryRisks::class)->handle(app(AlertManager::class));
        $this->assertDatabaseHas('alerts', [
            'organization_id' => $organization,
            'deduplication_key' => "stock-low:{$branch}:{$productId}",
            'event' => 'StockBelowMinimum', 'severity' => 'high',
            'recipient' => 'purchasing', 'status' => 'open',
        ]);
        $stockAlert = DB::table('alerts')
            ->where('deduplication_key', "stock-low:{$branch}:{$productId}")->first();
        self::assertStringContainsString('Molino del Plata', $stockAlert->action);

        $location = DB::table('locations')->insertGetId([
            'organization_id' => $organization, 'branch_id' => $branch,
            'name' => 'Stock recuperado', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_lots')->insert([
            'organization_id' => $organization, 'branch_id' => $branch,
            'product_id' => $productId, 'location_id' => $location, 'code' => 'RESTOCK',
            'unit' => 'g', 'quantity' => '1500.000', 'reserved_quantity' => '0.000',
            'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(DetectInventoryRisks::class)->handle(app(AlertManager::class));
        $this->assertDatabaseHas('alerts', [
            'deduplication_key' => "stock-low:{$branch}:{$productId}", 'status' => 'resolved',
        ]);

        $order = $this->createSentOrder($supplier['id'], $catalog['id']);
        DB::table('purchase_orders')->where('id', $order['id'])->update([
            'status' => 'approved', 'approved_at' => now()->subHours(3),
        ]);
        DB::table('supplier_product_prices')->where('supplier_product_id', $catalog['id'])->delete();
        app(DetectPurchaseRisks::class)->handle(app(AlertManager::class));
        $this->assertDatabaseHas('alerts', [
            'deduplication_key' => "purchase-order:{$order['id']}:approved-not-sent",
            'event' => 'PurchaseOrderApprovedNotSent', 'status' => 'open',
        ]);
        $this->assertDatabaseHas('alerts', [
            'deduplication_key' => "supplier-product:{$catalog['id']}:without-price",
            'event' => 'SupplierProductWithoutValidPrice', 'status' => 'open',
        ]);
    }

    private function supplierAndCatalog(int $organization): array
    {
        $supplier = $this->postJson('/api/v1/suppliers', [
            'trade_name' => 'Molino del Plata', 'tax_id' => '30-12345678-9',
            'lead_time_days' => 2, 'active' => true,
        ])->assertCreated()->json('data');
        $product = DB::table('products')->insertGetId([
            'organization_id' => $organization, 'name' => 'Harina 000',
            'type' => 'ingredient', 'unit' => 'g', 'minimum_stock' => '1000.000',
            'price' => '0.00', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $catalog = $this->postJson('/api/v1/supplier-products', [
            'supplier_id' => $supplier['id'], 'product_id' => $product,
            'supplier_code' => 'H000', 'purchase_unit' => 'kg',
            'conversion_factor' => '1000.000000', 'minimum_quantity' => '1.000',
            'lead_time_days' => 2, 'preferred' => true, 'active' => true,
            'price' => '2000.00', 'currency' => 'ARS',
            'price_valid_from' => today()->toDateString(),
        ])->assertCreated()->json('data');

        return [$supplier, $catalog];
    }

    private function createSentOrder(int $supplierId, int $catalogId): array
    {
        $suffix = str()->uuid()->toString();
        $order = $this->withHeader('Idempotency-Key', "create-{$suffix}")
            ->postJson('/api/v1/purchase-orders', [
                'supplier_id' => $supplierId, 'ordered_at' => today()->toDateString(),
                'currency' => 'ARS', 'items' => [[
                    'supplier_product_id' => $catalogId, 'quantity' => '1.000',
                ]],
            ])->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', "approve-{$suffix}")
            ->postJson("/api/v1/purchase-orders/{$order['id']}/transitions", ['status' => 'approved'])
            ->assertOk();
        $this->withHeader('Idempotency-Key', "send-{$suffix}")
            ->postJson("/api/v1/purchase-orders/{$order['id']}/transitions", ['status' => 'sent'])
            ->assertOk();

        return $order;
    }

    private function tenant(string $role): array
    {
        $organization = DB::table('organizations')->insertGetId([
            'name' => "Procurement {$role}", 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = DB::table('branches')->insertGetId([
            'organization_id' => $organization, 'name' => "Branch {$role}", 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        DB::table('organization_user')->insert([
            'organization_id' => $organization, 'user_id' => $user->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branch_user')->insert([
            'branch_id' => $branch, 'user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$organization, $branch, $user];
    }

    private function headers(int $organization, int $branch): array
    {
        return [
            'X-Organization-ID' => (string) $organization,
            'X-Branch-ID' => (string) $branch,
        ];
    }
}
