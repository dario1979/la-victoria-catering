<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionRecipeConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private int $organization;

    private int $branch;

    private int $otherBranch;

    private int $location;

    private int $otherLocation;

    private int $finishedProduct;

    private int $flour;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->insert('organizations', ['name' => 'Production Org']);
        $this->branch = $this->insert('branches', [
            'organization_id' => $this->organization, 'name' => 'Main', 'active' => true,
        ]);
        $this->otherBranch = $this->insert('branches', [
            'organization_id' => $this->organization, 'name' => 'Other', 'active' => true,
        ]);
        $this->user = User::factory()->create();
        $this->insert('organization_user', [
            'organization_id' => $this->organization, 'user_id' => $this->user->id, 'role' => 'admin',
        ]);
        foreach ([$this->branch, $this->otherBranch] as $branch) {
            $this->insert('branch_user', ['branch_id' => $branch, 'user_id' => $this->user->id]);
        }
        $this->location = $this->insert('locations', [
            'organization_id' => $this->organization, 'branch_id' => $this->branch,
            'name' => 'Production', 'active' => true,
        ]);
        $this->otherLocation = $this->insert('locations', [
            'organization_id' => $this->organization, 'branch_id' => $this->otherBranch,
            'name' => 'Other Stock', 'active' => true,
        ]);
        $this->finishedProduct = $this->product('Bread tray', 'unit', 'finished_product');
        $this->flour = $this->product('Flour', 'kg', 'raw_material');
        $this->actingAs($this->user)->withHeaders([
            'X-Organization-ID' => (string) $this->organization,
            'X-Branch-ID' => (string) $this->branch,
        ]);
    }

    public function test_completes_production_with_multi_lot_fefo_and_bidirectional_traceability(): void
    {
        $expired = $this->lot($this->flour, $this->location, 'EXPIRED', '5.000', now()->subDay()->toDateString());
        $other = $this->lot($this->flour, $this->otherLocation, 'OTHER', '5.000', now()->addDay()->toDateString());
        $first = $this->lot($this->flour, $this->location, 'FIRST', '0.600', now()->addDay()->toDateString());
        $second = $this->lot($this->flour, $this->location, 'SECOND', '1.000', now()->addDays(5)->toDateString());
        [$recipe, $production] = $this->production('15.000');

        $requirements = $this->getJson("/api/v1/production-orders/{$production}/requirements")
            ->assertOk()->assertJsonPath('data.can_produce', true)->json('data');
        $this->assertSame('1.500', $requirements['ingredients'][0]['required_quantity']);
        $this->assertSame([$first, $second], array_column($requirements['ingredients'][0]['candidate_lots'], 'lot_id'));

        $this->withHeader('Idempotency-Key', 'start-1')
            ->postJson("/api/v1/production-orders/{$production}/start")->assertOk();
        $payload = [
            'actual_yield' => '14.000', 'unit' => 'unit', 'waste_quantity' => '1.000',
            'destination_location_id' => $this->location,
            'manufactured_at' => now()->toISOString(),
            'expires_at' => now()->addDays(4)->toDateString(),
            'observations' => 'Validated batch',
        ];
        $completed = $this->withHeader('Idempotency-Key', 'complete-1')
            ->postJson("/api/v1/production-orders/{$production}/complete", $payload)
            ->assertOk()->assertJsonPath('data.status', 'completed')->json('data');
        $producedLot = $completed['produced_lot'];

        $this->assertDatabaseHas('inventory_lots', ['id' => $expired, 'quantity' => 5]);
        $this->assertDatabaseHas('inventory_lots', ['id' => $other, 'quantity' => 5]);
        $this->assertDatabaseHas('inventory_lots', ['id' => $first, 'quantity' => 0]);
        $this->assertDatabaseHas('inventory_lots', ['id' => $second, 'quantity' => 0.1]);
        $this->assertDatabaseHas('inventory_lots', [
            'id' => $producedLot['id'], 'production_batch_id' => $production,
            'recipe_id' => $recipe, 'recipe_version' => 1, 'quantity' => 14,
        ]);
        $this->assertDatabaseCount('production_consumptions', 2);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_lot_id' => $first, 'type' => 'production_consumption', 'quantity' => -0.6,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_lot_id' => $producedLot['id'], 'type' => 'production_output', 'quantity' => 14,
        ]);

        $trace = $this->getJson("/api/v1/production-orders/{$production}/traceability")
            ->assertOk()->json('data');
        $this->assertSame(1, $trace['recipe_snapshot']['version']);
        $this->assertCount(2, $trace['consumed_lots']);
        $this->assertSame($producedLot['id'], $trace['produced_lot']['id']);
        $this->getJson("/api/v1/lots/{$first}")
            ->assertOk()->assertJsonPath('production_traceability.0.production_batch_id', $production);

        $this->withHeader('Idempotency-Key', 'complete-1')
            ->postJson("/api/v1/production-orders/{$production}/complete", $payload)
            ->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('production_consumptions', 2);
        $this->assertDatabaseCount('inventory_lots', 5);
    }

    public function test_recalculates_inside_completion_and_rolls_back_when_stock_was_exhausted(): void
    {
        $lot = $this->lot($this->flour, $this->location, 'AVAILABLE', '1.000', now()->addDays(5)->toDateString());
        [, $production] = $this->production('10.000');
        $this->withHeader('Idempotency-Key', 'start-2')
            ->postJson("/api/v1/production-orders/{$production}/start")->assertOk();
        DB::table('inventory_lots')->where('id', $lot)->update(['quantity' => '0.000']);

        $this->withHeader('Idempotency-Key', 'complete-2')
            ->postJson("/api/v1/production-orders/{$production}/complete", [
                'actual_yield' => '10.000', 'unit' => 'unit', 'waste_quantity' => '0.000',
                'destination_location_id' => $this->location,
                'manufactured_at' => now()->toISOString(),
            ])->assertUnprocessable()->assertJsonValidationErrors('ingredients');

        $this->assertDatabaseCount('production_consumptions', 0);
        $this->assertDatabaseMissing('inventory_lots', ['production_batch_id' => $production]);
        $this->assertDatabaseHas('production_batches', ['id' => $production, 'status' => 'in_progress']);
        $this->assertDatabaseHas('alerts', [
            'deduplication_key' => "production:{$production}:ingredients-insufficient", 'status' => 'open',
        ]);
    }

    public function test_recipe_snapshot_is_immutable_and_payload_reuse_is_rejected(): void
    {
        $this->lot($this->flour, $this->location, 'FLOUR', '5.000', now()->addDays(5)->toDateString());
        [$recipeOne, $production] = $this->production('10.000');
        $recipeTwo = $this->createRecipe('2.000');

        $this->assertNotSame($recipeOne, $recipeTwo);
        $this->assertDatabaseHas('recipes', ['id' => $recipeTwo, 'version' => 2]);
        $snapshot = DB::table('production_batches')->where('id', $production)->value('recipe_snapshot');
        $snapshot = json_decode($snapshot, true);
        $this->assertSame(1, $snapshot['version']);
        $this->assertSame('1.000', $snapshot['items'][0]['quantity']);

        $this->withHeader('Idempotency-Key', 'start-3')
            ->postJson("/api/v1/production-orders/{$production}/start")->assertOk();
        $payload = [
            'actual_yield' => '10.000', 'unit' => 'unit', 'waste_quantity' => '0.000',
            'destination_location_id' => $this->location,
            'manufactured_at' => now()->toISOString(),
            'expires_at' => now()->addDays(4)->toDateString(),
        ];
        $this->withHeader('Idempotency-Key', 'complete-3')
            ->postJson("/api/v1/production-orders/{$production}/complete", $payload)->assertOk();
        $this->withHeader('Idempotency-Key', 'complete-3')
            ->postJson("/api/v1/production-orders/{$production}/complete", [
                ...$payload, 'actual_yield' => '9.000',
            ])->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
    }

    public function test_requirements_do_not_cross_branch(): void
    {
        $this->lot($this->flour, $this->otherLocation, 'OTHER-ONLY', '5.000', now()->addDay()->toDateString());
        [, $production] = $this->production('10.000');

        $this->getJson("/api/v1/production-orders/{$production}/requirements")
            ->assertOk()
            ->assertJsonPath('data.can_produce', false)
            ->assertJsonPath('data.ingredients.0.available_quantity', '0.000');
    }

    public function test_recipe_detail_and_status_contracts_are_available(): void
    {
        $recipe = $this->createRecipe('1.000');

        $this->getJson("/api/v1/recipes/{$recipe}")
            ->assertOk()
            ->assertJsonPath('data.product.name', 'Bread tray')
            ->assertJsonPath('data.items.0.ingredient.name', 'Flour');

        $this->patchJson("/api/v1/recipes/{$recipe}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    private function production(string $plannedQuantity): array
    {
        $recipe = $this->createRecipe('1.000');
        $order = $this->insert('orders', [
            'organization_id' => $this->organization, 'branch_id' => $this->branch,
            'customer_name' => 'Production customer', 'status' => 'confirmed',
            'total' => '100.00', 'paid_total' => '0.00',
        ]);
        $this->insert('order_items', [
            'order_id' => $order, 'product_id' => $this->finishedProduct,
            'quantity' => $plannedQuantity, 'unit_price' => '10.00',
        ]);
        $production = $this->withHeader('Idempotency-Key', 'production-'.$order)
            ->postJson('/api/v1/production-orders', [
                'order_id' => $order, 'recipe_id' => $recipe,
                'planned_quantity' => $plannedQuantity, 'unit' => 'unit',
            ])->assertCreated()->json('data.id');

        return [$recipe, $production];
    }

    private function createRecipe(string $flourQuantity): int
    {
        return $this->postJson('/api/v1/recipes', [
            'product_id' => $this->finishedProduct,
            'expected_yield' => '10.000',
            'yield_unit' => 'unit',
            'theoretical_waste_percent' => '5.00',
            'items' => [[
                'ingredient_product_id' => $this->flour,
                'quantity' => $flourQuantity,
                'unit' => 'kg',
            ]],
        ])->assertCreated()->json('data.id');
    }

    private function product(string $name, string $unit, string $type): int
    {
        return $this->insert('products', [
            'organization_id' => $this->organization, 'name' => $name,
            'type' => $type, 'unit' => $unit, 'minimum_stock' => '0.000',
            'price' => '0.00', 'active' => true,
        ]);
    }

    private function lot(int $product, int $location, string $code, string $quantity, string $expires): int
    {
        return $this->insert('inventory_lots', [
            'organization_id' => $this->organization,
            'branch_id' => $location === $this->location ? $this->branch : $this->otherBranch,
            'product_id' => $product, 'location_id' => $location, 'code' => $code,
            'unit' => 'kg', 'quantity' => $quantity, 'reserved_quantity' => '0.000',
            'expires_at' => $expires, 'status' => 'available',
        ]);
    }

    private function insert(string $table, array $data): int
    {
        return DB::table($table)->insertGetId([
            ...$data, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
