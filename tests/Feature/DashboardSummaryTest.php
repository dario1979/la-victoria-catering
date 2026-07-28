<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\InventoryLot;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_is_exact_beyond_the_first_page_and_tenant_scoped(): void
    {
        [$organization, $branch, $user] = $this->tenant('A');
        $otherBranch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Other Branch',
            'active' => true,
        ]);
        [$otherOrganization, $otherOrganizationBranch] = $this->tenant('B');

        foreach (range(1, 30) as $index) {
            Order::create([
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'customer_name' => "Cliente {$index}",
                'status' => $index === 3 ? 'delivered' : 'confirmed',
                'total' => '100.00',
                'paid_total' => '10.00',
                'required_at' => $index <= 3 ? now()->subDay() : now()->addDay(),
            ]);
        }
        foreach (range(1, 4) as $index) {
            Order::create([
                'organization_id' => $organization->id,
                'branch_id' => $otherBranch->id,
                'customer_name' => "Otra sucursal {$index}",
                'total' => '999.00',
            ]);
        }
        Order::create([
            'organization_id' => $otherOrganization->id,
            'branch_id' => $otherOrganizationBranch->id,
            'customer_name' => 'Otra organización',
            'total' => '999.00',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Producto crítico',
            'unit' => 'unit',
            'minimum_stock' => '5.000',
        ]);
        $healthyProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Producto sano',
            'unit' => 'unit',
            'minimum_stock' => '5.000',
        ]);
        $location = Location::create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'name' => 'Principal',
        ]);
        InventoryLot::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'code' => 'LOW',
            'unit' => 'unit',
            'quantity' => '3.000',
        ]);
        InventoryLot::create([
            'product_id' => $healthyProduct->id,
            'location_id' => $location->id,
            'code' => 'OK',
            'unit' => 'unit',
            'quantity' => '10.000',
        ]);
        $recipe = Recipe::create([
            'product_id' => $healthyProduct->id,
            'version' => 1,
            'expected_yield' => '10.000',
            'yield_unit' => 'unit',
        ]);
        foreach (['planned', 'in_progress', 'completed'] as $status) {
            ProductionBatch::create([
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'recipe_id' => $recipe->id,
                'status' => $status,
                'planned_quantity' => '10.000',
                'unit' => 'unit',
            ]);
        }
        ProductionBatch::create([
            'organization_id' => $organization->id,
            'branch_id' => $otherBranch->id,
            'recipe_id' => $recipe->id,
            'status' => 'planned',
            'planned_quantity' => '10.000',
            'unit' => 'unit',
        ]);

        $this->alert($organization, null, 'global');
        $this->alert($organization, $branch, 'branch');
        $this->alert($organization, $otherBranch, 'other-branch');
        $this->alert($otherOrganization, $otherOrganizationBranch, 'other-organization');

        $this->actingAs($user)
            ->withHeaders($this->headers($organization, $branch))
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.metrics.orders_total', 30)
            ->assertJsonPath('data.metrics.orders_today', 30)
            ->assertJsonPath('data.metrics.overdue_orders', 2)
            ->assertJsonPath('data.metrics.pending_production', 2)
            ->assertJsonPath('data.metrics.outstanding_balance', '2700.00')
            ->assertJsonPath('data.metrics.open_alerts', 2)
            ->assertJsonPath('data.metrics.critical_stock', 1)
            ->assertJsonCount(5, 'data.recent_orders')
            ->assertJsonCount(2, 'data.open_alerts')
            ->assertJsonMissing(['customer_name' => 'Otra organización'])
            ->assertJsonMissing(['deduplication_key' => 'other-branch']);
    }

    private function alert(Organization $organization, ?Branch $branch, string $key): void
    {
        Alert::create([
            'organization_id' => $organization->id,
            'branch_id' => $branch?->id,
            'deduplication_key' => $key,
            'event' => 'StockLow',
            'condition' => 'below minimum',
            'severity' => 'warning',
            'recipient' => 'inventory',
            'action' => 'Review stock',
            'last_seen_at' => now(),
        ]);
    }

    private function tenant(string $suffix): array
    {
        $organization = Organization::create(['name' => "Organization {$suffix}"]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => "Branch {$suffix}",
            'active' => true,
        ]);
        $user = User::factory()->create();
        $user->organizations()->attach($organization, ['role' => 'admin']);
        $user->branches()->attach($branch);

        return [$organization, $branch, $user];
    }

    private function headers(Organization $organization, Branch $branch): array
    {
        return [
            'X-Organization-ID' => (string) $organization->id,
            'X-Branch-ID' => (string) $branch->id,
        ];
    }
}
