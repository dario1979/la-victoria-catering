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
            'name' => 'La Victoria Demo', 'created_at' => now(), 'updated_at' => now(),
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
        foreach ($roles as $role => $email) {
            $user = User::create([
                'name' => ucfirst($role).' Demo',
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make(env('DEMO_USER_PASSWORD', 'ChangeMe-Demo-123!')),
            ]);
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
        $locationId = DB::table('locations')->insertGetId([
            'organization_id' => $organizationId, 'branch_id' => $branchIds->first(),
            'name' => 'Depósito Centro', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'organization_id' => $organizationId, 'name' => 'Docena de empanadas',
            'type' => 'finished_product', 'unit' => 'unit', 'minimum_stock' => '6.000',
            'price' => '18000.00', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_lots')->insert([
            'product_id' => $productId, 'location_id' => $locationId, 'code' => 'DEMO-001',
            'unit' => 'unit', 'quantity' => '50.000', 'reserved_quantity' => '0.000',
            'expires_at' => now()->addDays(7)->toDateString(), 'status' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customers')->insert([
            'organization_id' => $organizationId, 'name' => 'Cliente mostrador',
            'email' => 'cliente@example.test', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recipes')->insert([
            'product_id' => $productId, 'version' => 1, 'expected_yield' => '12.000',
            'yield_unit' => 'unit', 'status' => 'approved', 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
