<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'La Victoria Bakery', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchIds = collect(['Centro', 'Producción'])->map(fn (string $name) => DB::table('branches')->insertGetId([
            'organization_id' => $organizationId, 'name' => $name, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $roles = [
            'admin' => 'admin@lavictoria.test',
            'sales' => 'ventas@lavictoria.test',
            'production' => 'produccion@lavictoria.test',
            'inventory' => 'inventario@lavictoria.test',
            'purchasing' => 'compras@lavictoria.test',
            'finance' => 'cobranzas@lavictoria.test',
        ];
        $userIds = [];
        foreach ($roles as $role => $email) {
            $user = User::create([
                'name' => ucfirst($role).' Demo',
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make(env('DEMO_USER_PASSWORD', '123456')),
            ]);
            $userIds[$role] = $user->id;
            DB::table('organization_user')->insert([
                'organization_id' => $organizationId, 'user_id' => $user->id, 'role' => $role,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($branchIds as $branchId) {
                DB::table('branch_user')->insert([
                    'branch_id' => $branchId, 'user_id' => $user->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $cashRegisterId = DB::table('cash_registers')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchIds->first(),
            'name' => 'Mostrador Centro', 'active' => true, 'created_by' => $userIds['admin'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['admin', 'sales', 'finance'] as $role) {
            DB::table('cash_register_user')->insert([
                'cash_register_id' => $cashRegisterId, 'user_id' => $userIds[$role],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $locationId = DB::table('locations')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchIds->first(),
            'name' => 'Depósito Centro', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'organization_id' => $organizationId, 'name' => 'Docena de empanadas',
            'type' => 'finished_product', 'unit' => 'unit', 'minimum_stock' => '6.000',
            'price' => '18000.00', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ingredientId = DB::table('products')->insertGetId([
            'organization_id' => $organizationId, 'name' => 'Harina 000',
            'type' => 'raw_material', 'unit' => 'g', 'minimum_stock' => '5000.000',
            'price' => '0.00', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lotId = DB::table('inventory_lots')->insertGetId([
            'product_id' => $productId, 'location_id' => $locationId, 'code' => 'DEMO-001',
            'unit' => 'unit', 'quantity' => '50.000', 'reserved_quantity' => '0.000',
            'expires_at' => now()->addDays(7)->toDateString(), 'status' => 'available',
            'organization_id' => $organizationId, 'branch_id' => $branchIds->first(),
            'created_by' => $userIds['admin'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_movements')->insert([
            'organization_id' => $organizationId, 'branch_id' => $branchIds->first(),
            'product_id' => $productId, 'location_id' => $locationId, 'inventory_lot_id' => $lotId,
            'unit' => 'unit', 'quantity' => '50.000', 'type' => 'initial',
            'reason' => 'pilot_seed', 'performed_by' => $userIds['admin'],
            'idempotency_key' => 'pilot-seed:demo-lot',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customers')->insert([
            'organization_id' => $organizationId, 'name' => 'Cliente mostrador',
            'email' => 'cliente@example.test', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'organization_id' => $organizationId, 'trade_name' => 'Molino del Plata',
            'legal_name' => 'Molino del Plata SA', 'tax_id' => '30-70000000-1',
            'email' => 'compras@molino.example.test', 'phone' => '+54 11 5555 0101',
            'contact_name' => 'Mesa comercial', 'payment_terms' => 'Cuenta corriente a 15 días',
            'lead_time_days' => 2, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierProductId = DB::table('supplier_products')->insertGetId([
            'organization_id' => $organizationId, 'supplier_id' => $supplierId,
            'product_id' => $ingredientId, 'supplier_code' => 'HAR-000-25',
            'purchase_unit' => 'kg', 'conversion_factor' => '1000.000000',
            'minimum_quantity' => '5.000', 'lead_time_days' => 2,
            'preferred' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_product_prices')->insert([
            'supplier_product_id' => $supplierProductId, 'price' => '950.00',
            'currency' => 'ARS', 'valid_from' => today()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseOrderId = DB::table('purchase_orders')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchIds->first(),
            'supplier_id' => $supplierId, 'number' => 'OC-DEMO-001', 'status' => 'draft',
            'ordered_at' => today()->toDateString(), 'expected_at' => today()->addDays(2)->toDateString(),
            'currency' => 'ARS', 'payment_terms' => 'Cuenta corriente a 15 días',
            'subtotal' => '9500.00', 'tax_total' => '0.00', 'total' => '9500.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $purchaseOrderId, 'supplier_product_id' => $supplierProductId,
            'product_id' => $ingredientId, 'product_name' => 'Harina 000',
            'supplier_code' => 'HAR-000-25', 'purchase_unit' => 'kg', 'base_unit' => 'g',
            'conversion_factor' => '1000.000000', 'quantity' => '10.000',
            'received_quantity' => '0.000', 'unit_price' => '950.00',
            'tax_amount' => '0.00', 'subtotal' => '9500.00', 'total' => '9500.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recipes')->insert([
            'product_id' => $productId, 'version' => 1, 'expected_yield' => '12.000',
            'yield_unit' => 'unit', 'status' => 'approved', 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
