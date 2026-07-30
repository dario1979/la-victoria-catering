<?php

namespace App\Support\Pilot;

use App\Models\PilotScenario;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class PilotScenarioProvisioner
{
    public function provision(string $identifier): PilotScenario
    {
        $existing = PilotScenario::query()->where('identifier', $identifier)->first();
        if ($existing) {
            if ($existing->status === 'cleaned') {
                throw new RuntimeException('El identificador pertenece a un escenario ya limpiado; use uno nuevo.');
            }

            return $existing;
        }

        return DB::transaction(function () use ($identifier): PilotScenario {
            $stamp = substr(hash('sha256', $identifier), 0, 10);
            $now = now();
            $organizationId = DB::table('organizations')->insertGetId([
                'name' => "[PILOT {$identifier}] La Victoria Bakery",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $branchId = DB::table('branches')->insertGetId([
                'organization_id' => $organizationId,
                'name' => 'Sucursal Piloto',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $admin = $this->createUser("pilot-admin+{$stamp}@example.test", 'Administración Piloto');
            $sales = $this->createUser("pilot-sales+{$stamp}@example.test", 'Ventas Piloto');
            foreach ([[$admin, 'admin'], [$sales, 'sales']] as [$user, $role]) {
                DB::table('organization_user')->insert([
                    'organization_id' => $organizationId,
                    'user_id' => $user->id,
                    'role' => $role,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('branch_user')->insert([
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $api = new PilotApiClient($admin, $organizationId, $branchId);
            $customer = $api->post('/api/v1/customers', [
                'name' => 'Cliente Ficticio Piloto',
                'email' => "cliente+{$stamp}@example.test",
                'credit_limit' => '1000.00',
                'active' => true,
            ], expected: 201)['body']['data'];
            $finished = $api->post('/api/v1/products', [
                'name' => 'Bandeja Ficticia Piloto',
                'type' => 'finished_product',
                'unit' => 'unit',
                'minimum_stock' => '2.000',
                'price' => '100.00',
                'active' => true,
            ], expected: 201)['body']['data'];
            $ingredient = $api->post('/api/v1/products', [
                'name' => 'Ingrediente Ficticio Piloto',
                'type' => 'raw_material',
                'unit' => 'kg',
                'minimum_stock' => '1.000',
                'price' => '0.00',
                'active' => true,
            ], expected: 201)['body']['data'];
            $location = $api->post('/api/v1/locations', [
                'name' => 'Depósito Ficticio Piloto',
                'active' => true,
            ], expected: 201)['body']['data'];
            $supplier = $api->post('/api/v1/suppliers', [
                'trade_name' => 'Proveedor Ficticio Piloto',
                'legal_name' => 'Proveedor Ficticio Piloto SA',
                'tax_id' => "30-{$stamp}",
                'email' => "proveedor+{$stamp}@example.test",
                'lead_time_days' => 2,
                'payment_terms' => 'Cuenta corriente a 15 días',
                'active' => true,
            ], expected: 201)['body']['data'];
            $catalog = $api->post('/api/v1/supplier-products', [
                'supplier_id' => $supplier['id'],
                'product_id' => $ingredient['id'],
                'supplier_code' => "ING-{$stamp}",
                'purchase_unit' => 'kg',
                'conversion_factor' => '1.000000',
                'minimum_quantity' => '1.000',
                'lead_time_days' => 2,
                'preferred' => true,
                'active' => true,
                'price' => '100.00',
                'currency' => 'ARS',
                'price_valid_from' => today()->toDateString(),
            ], expected: 201)['body']['data'];
            $recipe = $api->post('/api/v1/recipes', [
                'product_id' => $finished['id'],
                'expected_yield' => '10.000',
                'yield_unit' => 'unit',
                'theoretical_waste_percent' => '5.00',
                'status' => 'approved',
                'items' => [[
                    'ingredient_product_id' => $ingredient['id'],
                    'quantity' => '1.000',
                    'unit' => 'kg',
                ]],
            ], expected: 201)['body']['data'];
            $register = $api->post('/api/v1/cash-registers', [
                'name' => 'Caja Ficticia Piloto',
                'authorized_user_ids' => [$admin->id],
            ], "pilot:{$identifier}:register", 201)['body']['data'];
            $lots = [];
            foreach ([
                ['expired', -1, '8.000'],
                ['fefo', 2, '8.000'],
                ['later', 10, '8.000'],
            ] as [$suffix, $days, $quantity]) {
                $lots[$suffix] = $api->post('/api/v1/lots/adjustments', [
                    'product_id' => $finished['id'],
                    'location_id' => $location['id'],
                    'code' => strtoupper("PILOT-{$stamp}-{$suffix}"),
                    'unit' => 'unit',
                    'expires_at' => today()->addDays($days)->toDateString(),
                    'quantity' => $quantity,
                    'reason' => 'Preparación técnica del escenario piloto',
                    'type' => 'receipt',
                ], "pilot:{$identifier}:lot:{$suffix}", 201)['body']['data'];
            }

            return PilotScenario::create([
                'identifier' => $identifier,
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'status' => 'seeded',
                'participants' => [
                    'admin_user_id' => $admin->id,
                    'sales_user_id' => $sales->id,
                    'admin_email' => $admin->email,
                    'sales_email' => $sales->email,
                ],
                'fixtures' => [
                    'customer_id' => $customer['id'],
                    'finished_product_id' => $finished['id'],
                    'ingredient_product_id' => $ingredient['id'],
                    'location_id' => $location['id'],
                    'supplier_id' => $supplier['id'],
                    'supplier_product_id' => $catalog['id'],
                    'recipe_id' => $recipe['id'],
                    'cash_register_id' => $register['id'],
                    'expired_lot_id' => $lots['expired']['id'],
                    'fefo_lot_id' => $lots['fefo']['id'],
                    'later_lot_id' => $lots['later']['id'],
                ],
            ]);
        }, 3);
    }

    private function createUser(string $email, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make(Str::random(48)),
        ]);
    }
}
