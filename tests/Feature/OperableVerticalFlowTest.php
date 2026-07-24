<?php

namespace Tests\Feature;

use App\Domain\Alerts\AlertManager;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperableVerticalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_complete_the_vertical_flow(): void
    {
        [$organization, $branch, $user] = $this->tenant();
        $this->actingAs($user)->withHeaders([
            'X-Organization-ID' => (string) $organization,
            'X-Branch-ID' => (string) $branch,
        ]);

        $customer = $this->postJson('/api/v1/customers', [
            'name' => 'Evento Demo', 'email' => 'evento@example.test',
        ])->assertCreated()->json('data');
        $product = $this->postJson('/api/v1/products', [
            'name' => 'Bandeja de panificados', 'type' => 'finished_product', 'unit' => 'unit',
            'minimum_stock' => '2.000', 'price' => '25000.00',
        ])->assertCreated()->json('data');
        $location = $this->postJson('/api/v1/locations', [
            'name' => 'Cámara principal',
        ])->assertCreated()->json('data');
        $lot = $this->withHeader('Idempotency-Key', 'stock-demo')->postJson('/api/v1/lots/adjustments', [
            'product_id' => $product['id'], 'location_id' => $location['id'],
            'code' => 'FLOW-001', 'unit' => 'unit', 'expires_at' => now()->addDays(5)->toDateString(),
            'quantity' => '10.000', 'reason' => 'initial receipt', 'type' => 'receipt',
        ])->assertCreated()->json('data');
        $recipe = DB::table('recipes')->insertGetId([
            'product_id' => $product['id'], 'version' => 1, 'expected_yield' => '1.000',
            'yield_unit' => 'unit', 'status' => 'approved', 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->withHeader('Idempotency-Key', 'order-demo')->postJson('/api/v1/orders', [
            'customer_id' => $customer['id'],
            'items' => [['product_id' => $product['id'], 'quantity' => '2.000', 'unit_price' => '25000.00']],
        ])->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'confirm-demo')
            ->postJson("/api/v1/orders/{$order['id']}/transitions", ['status' => 'confirmed'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $order['id'], 'inventory_lot_id' => $lot['id'], 'quantity' => 2,
        ]);

        $production = $this->withHeader('Idempotency-Key', 'production-demo')
            ->postJson('/api/v1/production-orders', [
                'order_id' => $order['id'], 'recipe_id' => $recipe,
                'planned_quantity' => '2.000', 'unit' => 'unit',
            ])->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'start-demo')
            ->postJson("/api/v1/production-orders/{$production['id']}/start")
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->withHeader('Idempotency-Key', 'complete-demo')
            ->postJson("/api/v1/production-orders/{$production['id']}/complete", [
                'actual_yield' => '2.000', 'unit' => 'unit', 'waste_quantity' => '0.000',
                'destination_location_id' => $location['id'],
                'manufactured_at' => now()->toISOString(),
                'expires_at' => now()->addDays(5)->toDateString(),
            ])->assertOk()->assertJsonPath('data.order.status', 'ready');

        $this->withHeader('Idempotency-Key', 'payment-demo')->postJson('/api/v1/payments', [
            'order_id' => $order['id'], 'amount' => '50000.00', 'method' => 'transfer',
        ])->assertCreated();
        $this->withHeader('Idempotency-Key', 'delivery-demo')
            ->postJson("/api/v1/orders/{$order['id']}/delivery", [
                'method' => 'pickup', 'notes' => 'Delivered to customer',
            ])->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertDatabaseHas('inventory_lots', [
            'id' => $lot['id'], 'quantity' => 8, 'reserved_quantity' => 0,
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $order['id'], 'status' => 'consumed',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $order['id'], 'type' => 'consumption', 'quantity' => -2,
        ]);

        app(AlertManager::class)->raise(
            $organization, "order:{$order['id']}:paid", 'PaymentReceived',
            'payment pending reconciliation', 'warning', 'finance', 'reconcile payment',
            $branch, Order::class, $order['id']
        );
        $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['event' => 'order.delivered', 'subject_id' => $order['id']]);
    }

    private function tenant(): array
    {
        $organization = DB::table('organizations')->insertGetId([
            'name' => 'Flow Org', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = DB::table('branches')->insertGetId([
            'organization_id' => $organization, 'name' => 'Flow Branch', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        DB::table('organization_user')->insert([
            'organization_id' => $organization, 'user_id' => $user->id, 'role' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branch_user')->insert([
            'branch_id' => $branch, 'user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$organization, $branch, $user];
    }
}
