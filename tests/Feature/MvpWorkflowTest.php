<?php

namespace Tests\Feature;

use App\Domain\Alerts\AlertManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MvpWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $productId;

    private int $locationId;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Test Bakery', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = DB::table('branches')->insertGetId([
            'organization_id' => $this->organizationId, 'name' => 'Main', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        DB::table('organization_user')->insert([
            'organization_id' => $this->organizationId, 'user_id' => $user->id, 'role' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branch_user')->insert([
            'branch_id' => $this->branchId, 'user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user)->withHeaders([
            'X-Organization-ID' => (string) $this->organizationId,
            'X-Branch-ID' => (string) $this->branchId,
        ]);
        $this->productId = DB::table('products')->insertGetId([
            'organization_id' => $this->organizationId, 'name' => 'Empanada', 'unit' => 'unit',
            'price' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->locationId = DB::table('locations')->insertGetId([
            'organization_id' => $this->organizationId, 'branch_id' => $this->branchId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_order_creation_and_confirmation_are_idempotent_and_reserve_fefo_stock(): void
    {
        $expired = $this->lot('OLD', 10, now()->subDay()->toDateString());
        $fresh = $this->lot('FRESH', 10, now()->addDay()->toDateString());
        $payload = [
            'organization_id' => $this->organizationId, 'customer_name' => 'Customer',
            'items' => [['product_id' => $this->productId, 'quantity' => 4, 'unit_price' => 1000]],
        ];
        $created = $this->withHeader('Idempotency-Key', 'order-1')->postJson('/api/v1/orders', $payload)
            ->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'order-1')->postJson('/api/v1/orders', $payload)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->withHeader('Idempotency-Key', 'confirm-1')
            ->postJson("/api/v1/orders/{$created['id']}/transitions", ['status' => 'confirmed'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->withHeader('Idempotency-Key', 'confirm-1')
            ->postJson("/api/v1/orders/{$created['id']}/transitions", ['status' => 'confirmed'])
            ->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('inventory_lots', ['id' => $expired, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('inventory_lots', ['id' => $fresh, 'reserved_quantity' => 4]);
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_invalid_order_transition_is_rejected_and_recorded(): void
    {
        $order = $this->order('in_production', 1000);

        $this->withHeader('Idempotency-Key', 'invalid-1')
            ->postJson("/api/v1/orders/{$order}/transitions", ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('order_transitions', [
            'order_id' => $order, 'from_status' => 'in_production',
            'to_status' => 'delivered', 'accepted' => false,
        ]);
    }

    public function test_fefo_never_reserves_a_lot_from_another_branch(): void
    {
        $otherBranch = DB::table('branches')->insertGetId([
            'organization_id' => $this->organizationId, 'name' => 'Other', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherLocation = DB::table('locations')->insertGetId([
            'organization_id' => $this->organizationId, 'branch_id' => $otherBranch,
            'name' => 'Other stock', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherLot = DB::table('inventory_lots')->insertGetId([
            'product_id' => $this->productId, 'location_id' => $otherLocation,
            'code' => 'OTHER', 'unit' => 'unit', 'quantity' => 20,
            'reserved_quantity' => 0, 'expires_at' => now()->addDay(),
            'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ownLot = $this->lot('OWN', 20, now()->addDays(3)->toDateString());
        $order = $this->order('draft', 1000);
        DB::table('order_items')->insert([
            'order_id' => $order, 'product_id' => $this->productId,
            'quantity' => 5, 'unit_price' => 200,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Idempotency-Key', 'branch-fefo')
            ->postJson("/api/v1/orders/{$order}/transitions", ['status' => 'confirmed'])->assertOk();

        $this->assertDatabaseHas('inventory_lots', ['id' => $otherLot, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('inventory_lots', ['id' => $ownLot, 'reserved_quantity' => 5]);
    }

    public function test_payment_retry_changes_balance_and_cash_once(): void
    {
        $order = $this->order('confirmed', 5000);
        $payload = ['order_id' => $order, 'amount' => 1200, 'method' => 'cash'];

        $this->withHeader('Idempotency-Key', 'payment-1')->postJson('/api/v1/payments', $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', 'payment-1')->postJson('/api/v1/payments', $payload)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseHas('orders', ['id' => $order, 'paid_total' => 1200]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_same_idempotency_key_cannot_be_reused_for_different_payload(): void
    {
        $order = $this->order('confirmed', 5000);
        $first = ['order_id' => $order, 'amount' => 1200, 'method' => 'cash'];
        $second = ['order_id' => $order, 'amount' => 1300, 'method' => 'cash'];
        $this->withHeader('Idempotency-Key', 'payment-1')->postJson('/api/v1/payments', $first)->assertCreated();
        $this->withHeader('Idempotency-Key', 'payment-1')->postJson('/api/v1/payments', $second)
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
    }

    public function test_repeated_alert_is_deduplicated_until_resolved(): void
    {
        $alerts = app(AlertManager::class);
        $first = $alerts->raise(
            $this->organizationId, 'stock:product:'.$this->productId,
            'StockBelowMinimum', 'available < minimum', 'warning', 'inventory-manager', 'replenish'
        );
        $second = $alerts->raise(
            $this->organizationId, 'stock:product:'.$this->productId,
            'StockBelowMinimum', 'available < minimum', 'warning', 'inventory-manager', 'replenish'
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->occurrences);
        $this->assertDatabaseCount('alerts', 1);

        $alerts->resolve($first->id);
        $third = $alerts->raise(
            $this->organizationId, 'stock:product:'.$this->productId,
            'StockBelowMinimum', 'available < minimum', 'warning', 'inventory-manager', 'replenish'
        );
        $this->assertNotSame($first->id, $third->id);
    }

    private function lot(string $code, float $quantity, string $expiry): int
    {
        return DB::table('inventory_lots')->insertGetId([
            'product_id' => $this->productId, 'location_id' => $this->locationId,
            'code' => $code, 'unit' => 'unit', 'quantity' => $quantity,
            'reserved_quantity' => 0, 'expires_at' => $expiry, 'status' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(string $status, float $total): int
    {
        return DB::table('orders')->insertGetId([
            'organization_id' => $this->organizationId, 'customer_name' => 'Customer',
            'branch_id' => $this->branchId,
            'status' => $status, 'total' => $total, 'paid_total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
