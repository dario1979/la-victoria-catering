<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryLot;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ServerDataTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_contract_paginates_searches_filters_and_sorts_on_the_server(): void
    {
        [$organization, $branch, $user] = $this->tenant('A');
        Customer::create(['organization_id' => $organization->id, 'name' => 'Zeta', 'active' => true]);
        Customer::create(['organization_id' => $organization->id, 'name' => 'Alfajor', 'active' => true]);
        Customer::create(['organization_id' => $organization->id, 'name' => 'Archivado', 'active' => false]);
        $headers = $this->headers($organization, $branch);

        $this->actingAs($user)->withHeaders($headers)->getJson(
            '/api/v1/customers?page=1&per_page=10&search=a&sort=name&direction=asc&filters[active]=1'
        )->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Alfajor')
            ->assertJsonPath('data.1.name', 'Zeta');
    }

    public function test_list_rejects_unapproved_sort_and_page_size(): void
    {
        [$organization, $branch, $user] = $this->tenant('A');

        $this->actingAs($user)->withHeaders($this->headers($organization, $branch))
            ->getJson('/api/v1/customers?sort=password&per_page=999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'per_page']);
    }

    public function test_list_accepts_operational_page_sizes(): void
    {
        [$organization, $branch, $user] = $this->tenant('A');

        $this->actingAs($user)
            ->withHeaders($this->headers($organization, $branch))
            ->getJson('/api/v1/customers?per_page=25')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_excel_export_is_tenant_scoped_and_streamed_as_an_excel_workbook(): void
    {
        [$organizationA, $branchA, $user] = $this->tenant('A');
        [$organizationB] = $this->tenant('B');
        Customer::create(['organization_id' => $organizationA->id, 'name' => 'Visible A']);
        Customer::create(['organization_id' => $organizationB->id, 'name' => 'Secret B']);

        $response = $this->actingAs($user)->withHeaders($this->headers($organizationA, $branchA))
            ->get('/api/v1/customers?export=xlsx&sort=name&direction=asc');

        $response->assertOk()->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $content = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('Visible A', $content);
        $this->assertStringNotContainsString('Secret B', $content);
    }

    public function test_lots_list_and_export_load_the_product_and_stay_branch_scoped(): void
    {
        [$organizationA, $branchA, $user] = $this->tenant('A');
        [$organizationB, $branchB] = $this->tenant('B');
        $productA = Product::create([
            'organization_id' => $organizationA->id,
            'name' => 'Harina visible',
            'unit' => 'kg',
        ]);
        $locationA = Location::create([
            'organization_id' => $organizationA->id,
            'branch_id' => $branchA->id,
            'name' => 'Depósito visible',
        ]);
        InventoryLot::create([
            'product_id' => $productA->id,
            'location_id' => $locationA->id,
            'code' => 'LOTE-VISIBLE',
            'unit' => 'kg',
            'quantity' => '10.000',
        ]);
        $productB = Product::create([
            'organization_id' => $organizationB->id,
            'name' => 'Harina secreta',
            'unit' => 'kg',
        ]);
        $locationB = Location::create([
            'organization_id' => $organizationB->id,
            'branch_id' => $branchB->id,
            'name' => 'Depósito secreto',
        ]);
        InventoryLot::create([
            'product_id' => $productB->id,
            'location_id' => $locationB->id,
            'code' => 'LOTE-SECRETO',
            'unit' => 'kg',
            'quantity' => '20.000',
        ]);

        $headers = $this->headers($organizationA, $branchA);
        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/lots')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.name', 'Harina visible')
            ->assertJsonPath('data.0.location.name', 'Depósito visible');

        $response = $this->actingAs($user)->withHeaders($headers)->get('/api/v1/lots?export=xlsx');
        $response->assertOk();
        $content = $this->worksheet($response);
        $this->assertStringContainsString('Harina visible', $content);
        $this->assertStringNotContainsString('Harina secreta', $content);
    }

    public function test_alerts_use_action_for_search_and_export_without_leaking_other_tenants(): void
    {
        [$organizationA, $branchA, $user] = $this->tenant('A');
        [$organizationB, $branchB] = $this->tenant('B');
        Alert::create([
            'organization_id' => $organizationA->id,
            'branch_id' => $branchA->id,
            'deduplication_key' => 'visible',
            'event' => 'StockLow',
            'condition' => 'stock below minimum',
            'severity' => 'warning',
            'recipient' => 'inventory',
            'action' => 'Relocate visible lot',
            'last_seen_at' => now(),
        ]);
        Alert::create([
            'organization_id' => $organizationB->id,
            'branch_id' => $branchB->id,
            'deduplication_key' => 'secret',
            'event' => 'StockLow',
            'condition' => 'stock below minimum',
            'severity' => 'warning',
            'recipient' => 'inventory',
            'action' => 'Relocate secret lot',
            'last_seen_at' => now(),
        ]);

        $headers = $this->headers($organizationA, $branchA);
        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/alerts?search=relocate')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'Relocate visible lot')
            ->assertJsonMissingPath('data.0.expected_action');

        $response = $this->actingAs($user)->withHeaders($headers)->get('/api/v1/alerts?export=xlsx');
        $response->assertOk();
        $content = $this->worksheet($response);
        $this->assertStringContainsString('Relocate visible lot', $content);
        $this->assertStringNotContainsString('Relocate secret lot', $content);
    }

    public function test_search_is_case_insensitive_and_recipe_and_production_search_are_real(): void
    {
        [$organization, $branch, $user] = $this->tenant('A');
        Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Sergio Eventos',
        ]);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Medialuna Especial',
            'unit' => 'unit',
        ]);
        $recipe = Recipe::create([
            'product_id' => $product->id,
            'version' => 7,
            'expected_yield' => '12.000',
            'yield_unit' => 'unit',
            'status' => 'approved',
        ]);
        $order = Order::create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'customer_name' => 'Fiesta Primavera',
            'status' => 'confirmed',
        ]);
        ProductionBatch::create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'recipe_id' => $recipe->id,
            'status' => 'planned',
            'planned_quantity' => '24.000',
            'unit' => 'unit',
        ]);
        $headers = $this->headers($organization, $branch);

        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/customers?search=sergio')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/recipes?search=medialuna')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/recipes?search=no-existe')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/production-orders?search=primavera')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/production-orders?search=no-existe')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function worksheet(TestResponse $response): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $content = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertIsString($content);

        return $content;
    }

    private function tenant(string $suffix): array
    {
        $organization = Organization::create(['name' => "Organization {$suffix}"]);
        $branch = Branch::create([
            'organization_id' => $organization->id, 'name' => "Branch {$suffix}", 'active' => true,
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
