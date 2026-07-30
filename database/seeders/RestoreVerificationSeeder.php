<?php

namespace Database\Seeders;

use App\Models\ProductionBatch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RestoreVerificationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || getenv('RESTORE_TEST_SCENARIO') !== 'true') {
            throw new RuntimeException('Restore verification data requires local/testing and RESTORE_TEST_SCENARIO=true.');
        }
        if (DB::table('orders')->where('customer_name', 'Restore Verification')->exists()) {
            return;
        }

        $now = now()->utc();
        $organizationId = (int) DB::table('organizations')->value('id');
        $branchId = (int) DB::table('branches')->orderBy('id')->value('id');
        $userId = (int) DB::table('users')->where('email', 'admin@lavictoria.test')->value('id');
        $customerId = (int) DB::table('customers')->where('organization_id', $organizationId)->value('id');
        $finishedProductId = (int) DB::table('products')->where('type', 'finished_product')->value('id');
        $ingredientId = (int) DB::table('products')->where('type', 'raw_material')->value('id');
        $locationId = (int) DB::table('locations')->where('branch_id', $branchId)->value('id');
        $recipeId = (int) DB::table('recipes')->where('product_id', $finishedProductId)->value('id');
        $supplierId = (int) DB::table('suppliers')->where('organization_id', $organizationId)->value('id');
        $purchaseOrderId = (int) DB::table('purchase_orders')->where('supplier_id', $supplierId)->value('id');
        $purchaseOrderItemId = (int) DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchaseOrderId)->value('id');
        $cashRegisterId = (int) DB::table('cash_registers')->where('branch_id', $branchId)->value('id');

        DB::table('recipe_items')->insert([
            'recipe_id' => $recipeId, 'ingredient_product_id' => $ingredientId,
            'quantity' => '1000.000', 'unit' => 'g', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'customer_id' => $customerId, 'customer_name' => 'Restore Verification',
            'status' => 'ready', 'total' => '100.00', 'paid_total' => '40.00',
            'required_at' => $now->addDay(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $finishedProductId,
            'quantity' => '1.000', 'unit_price' => '100.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([
            ['draft', 'confirmed'],
            ['confirmed', 'in_production'],
            ['in_production', 'ready'],
        ] as [$from, $to]) {
            DB::table('order_transitions')->insert([
                'order_id' => $orderId, 'from_status' => $from, 'to_status' => $to,
                'actor_type' => User::class, 'actor_id' => $userId, 'accepted' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $finishedLotId = (int) DB::table('inventory_lots')->where('product_id', $finishedProductId)->value('id');
        DB::table('inventory_lots')->where('id', $finishedLotId)->update(['reserved_quantity' => '1.000']);
        DB::table('stock_reservations')->insert([
            'order_id' => $orderId, 'inventory_lot_id' => $finishedLotId,
            'product_id' => $finishedProductId, 'location_id' => $locationId,
            'unit' => 'unit', 'quantity' => '1.000', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('purchase_orders')->where('id', $purchaseOrderId)->update([
            'status' => 'partially_received', 'approved_by' => $userId, 'approved_at' => $now,
            'sent_by' => $userId, 'sent_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('purchase_order_items')->where('id', $purchaseOrderItemId)->update([
            'received_quantity' => '2.000', 'updated_at' => $now,
        ]);
        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'purchase_order_id' => $purchaseOrderId, 'number' => 'REC-RESTORE-001',
            'received_at' => $now, 'created_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $ingredientLotId = DB::table('inventory_lots')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'product_id' => $ingredientId, 'location_id' => $locationId,
            'code' => 'RESTORE-ING-001', 'unit' => 'g', 'quantity' => '1000.000',
            'reserved_quantity' => '0.000', 'status' => 'available', 'created_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $receiptMovementId = DB::table('stock_movements')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'product_id' => $ingredientId, 'location_id' => $locationId,
            'inventory_lot_id' => $ingredientLotId, 'unit' => 'g',
            'quantity' => '2000.000', 'type' => 'purchase_receipt',
            'reason' => 'restore_verification_receipt', 'performed_by' => $userId,
            'idempotency_key' => 'restore-test:receipt',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receiptId, 'purchase_order_item_id' => $purchaseOrderItemId,
            'product_id' => $ingredientId, 'location_id' => $locationId,
            'received_quantity' => '2.000', 'accepted_quantity' => '2.000',
            'rejected_quantity' => '0.000', 'lot_code' => 'RESTORE-ING-001',
            'actual_unit_cost' => '950.00', 'inventory_lot_id' => $ingredientLotId,
            'stock_movement_id' => $receiptMovementId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $payableId = DB::table('accounts_payable')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'supplier_id' => $supplierId, 'purchase_order_id' => $purchaseOrderId,
            'purchase_receipt_id' => $receiptId, 'document' => 'REC-RESTORE-001',
            'document_date' => $now->toDateString(), 'due_on' => $now->addDays(15)->toDateString(),
            'currency' => 'ARS', 'total_cents' => 190000, 'paid_cents' => 50000,
            'status' => 'partial', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('accounts_payable_payments')->insert([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'account_payable_id' => $payableId, 'amount_cents' => 50000,
            'method' => 'transfer', 'performed_by' => $userId, 'occurred_at' => $now,
            'idempotency_key' => 'restore-test:payable-payment',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $batchId = DB::table('production_batches')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'order_id' => $orderId, 'recipe_id' => $recipeId, 'status' => 'completed',
            'planned_quantity' => '12.000', 'actual_yield' => '12.000', 'waste_quantity' => '0.000',
            'unit' => 'unit', 'recipe_snapshot' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
            'destination_location_id' => $locationId, 'manufactured_at' => $now,
            'started_at' => $now, 'completed_at' => $now, 'completed_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $consumptionMovementId = DB::table('stock_movements')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'product_id' => $ingredientId, 'location_id' => $locationId,
            'inventory_lot_id' => $ingredientLotId, 'unit' => 'g', 'quantity' => '-1000.000',
            'type' => 'production_consumption', 'reason' => 'restore_verification_production',
            'reference_type' => ProductionBatch::class, 'reference_id' => $batchId,
            'performed_by' => $userId, 'idempotency_key' => 'restore-test:production-consumption',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('production_consumptions')->insert([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'production_batch_id' => $batchId, 'snapshot_item_index' => 0,
            'ingredient_product_id' => $ingredientId, 'inventory_lot_id' => $ingredientLotId,
            'quantity' => '1000.000', 'unit' => 'g', 'stock_movement_id' => $consumptionMovementId,
            'performed_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $producedLotId = DB::table('inventory_lots')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'product_id' => $finishedProductId, 'location_id' => $locationId,
            'code' => 'RESTORE-OUT-001', 'unit' => 'unit', 'quantity' => '12.000',
            'reserved_quantity' => '0.000', 'status' => 'available',
            'production_batch_id' => $batchId, 'recipe_id' => $recipeId, 'recipe_version' => 1,
            'created_by' => $userId, 'manufactured_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('stock_movements')->insert([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'product_id' => $finishedProductId, 'location_id' => $locationId,
            'inventory_lot_id' => $producedLotId, 'unit' => 'unit', 'quantity' => '12.000',
            'type' => 'production_output', 'reason' => 'restore_verification_production',
            'reference_type' => ProductionBatch::class, 'reference_id' => $batchId,
            'performed_by' => $userId, 'idempotency_key' => 'restore-test:production-output',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $cashSessionId = DB::table('cash_sessions')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'cash_register_id' => $cashRegisterId, 'status' => 'closed',
            'opening_balance_cents' => 1000, 'expected_balance_cents' => 5000,
            'counted_balance_cents' => 5000, 'difference_cents' => 0,
            'opened_by' => $userId, 'closed_by' => $userId,
            'opened_at' => $now, 'closed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('cash_movements')->insert([
            [
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'cash_session_id' => $cashSessionId, 'kind' => 'opening', 'amount_cents' => 1000,
                'reason' => 'Restore verification opening', 'performed_by' => $userId,
                'occurred_at' => $now, 'idempotency_key' => 'restore-test:cash-opening',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'cash_session_id' => $cashSessionId, 'kind' => 'payment', 'amount_cents' => 4000,
                'reason' => 'Restore verification payment', 'performed_by' => $userId,
                'occurred_at' => $now, 'idempotency_key' => 'restore-test:cash-payment',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        $paymentId = DB::table('payments')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'order_id' => $orderId, 'customer_id' => $customerId, 'cash_session_id' => $cashSessionId,
            'amount' => '40.00', 'amount_cents' => 4000, 'method' => 'cash',
            'status' => 'recorded', 'recorded_by' => $userId, 'occurred_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('customer_account_entries')->insert([
            [
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'customer_id' => $customerId, 'type' => 'charge', 'amount_cents' => 10000,
                'description' => 'Restore verification order', 'order_id' => $orderId,
                'payment_id' => null, 'reversal_of_id' => null, 'due_on' => null,
                'occurred_at' => $now, 'performed_by' => $userId,
                'idempotency_key' => 'restore-test:ledger-charge',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'customer_id' => $customerId, 'type' => 'payment', 'amount_cents' => -4000,
                'description' => 'Restore verification payment', 'order_id' => null,
                'payment_id' => $paymentId, 'reversal_of_id' => null, 'due_on' => null,
                'occurred_at' => $now, 'performed_by' => $userId,
                'idempotency_key' => 'restore-test:ledger-payment',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        DB::table('reconciliations')->insert([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'provider' => 'manual', 'internal_type' => 'payment', 'internal_id' => $paymentId,
            'external_reference' => 'RESTORE-RECON-001', 'internal_amount_cents' => 4000,
            'external_amount_cents' => 4000, 'difference_cents' => 0,
            'external_date' => $now->toDateString(), 'status' => 'matched',
            'reconciled_by' => $userId, 'reconciled_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $alertId = DB::table('alerts')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'deduplication_key' => 'restore-test:alert', 'event' => 'RestoreVerification',
            'condition' => 'controlled', 'severity' => 'info', 'recipient' => 'admin',
            'action' => 'verify restore', 'status' => 'acknowledged', 'occurrences' => 1,
            'last_seen_at' => $now, 'acknowledged_at' => $now, 'acknowledged_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('notification_deliveries')->insert([
            'organization_id' => $organizationId, 'branch_id' => $branchId,
            'alert_id' => $alertId, 'user_id' => $userId, 'channel' => 'in_app',
            'status' => 'delivered', 'deduplication_key' => 'restore-test:notification',
            'subject' => 'Restore verification', 'message' => 'Controlled test data',
            'action' => 'open operations', 'attempts' => 1, 'available_at' => $now,
            'sent_at' => $now, 'delivered_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
