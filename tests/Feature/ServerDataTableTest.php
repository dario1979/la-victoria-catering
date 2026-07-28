<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
