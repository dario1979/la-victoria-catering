<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_party_session_login_regenerates_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $oldSession = session()->getId();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_user_from_organization_a_cannot_read_or_mutate_organization_b(): void
    {
        [$organizationA, $branchA] = $this->tenant('A');
        [$organizationB, $branchB] = $this->tenant('B');
        $user = User::factory()->create();
        $user->organizations()->attach($organizationA, ['role' => 'admin']);
        $user->branches()->attach($branchA);
        $orderB = DB::table('orders')->insertGetId([
            'organization_id' => $organizationB->id,
            'customer_name' => 'Secret B',
            'status' => 'draft',
            'total' => 100,
            'paid_total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $headersA = ['X-Organization-ID' => $organizationA->id, 'X-Branch-ID' => $branchA->id];
        $this->actingAs($user)->withHeaders($headersA)
            ->postJson("/api/v1/orders/{$orderB}/transitions", ['status' => 'confirmed'])
            ->assertForbidden();

        $this->actingAs($user)->withHeaders([
            'X-Organization-ID' => $organizationB->id,
            'X-Branch-ID' => $branchB->id,
        ])->postJson("/api/v1/orders/{$orderB}/transitions", ['status' => 'confirmed'])
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $orderB, 'status' => 'draft']);
    }

    public function test_branch_must_be_active_belong_to_tenant_and_be_assigned_to_user(): void
    {
        [$organization, $branch] = $this->tenant('A');
        $otherBranch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Unassigned',
            'active' => true,
        ]);
        $user = User::factory()->create();
        $user->organizations()->attach($organization, ['role' => 'admin']);
        $user->branches()->attach($branch);

        $this->actingAs($user)->withHeaders([
            'X-Organization-ID' => $organization->id,
            'X-Branch-ID' => $otherBranch->id,
        ])->postJson('/api/v1/orders', [])->assertForbidden();
    }

    public function test_role_policy_denies_order_creation_to_read_only_user(): void
    {
        [$organization, $branch] = $this->tenant('A');
        $user = User::factory()->create();
        $user->organizations()->attach($organization, ['role' => 'viewer']);
        $user->branches()->attach($branch);

        $this->actingAs($user)->withHeaders([
            'X-Organization-ID' => $organization->id,
            'X-Branch-ID' => $branch->id,
        ])->postJson('/api/v1/orders', [
            'customer_name' => 'Customer',
            'items' => [],
        ])->assertForbidden();
    }

    public function test_active_branch_cannot_mutate_an_order_from_another_assigned_branch(): void
    {
        [$organization, $branchA] = $this->tenant('A');
        $branchB = Branch::create([
            'organization_id' => $organization->id, 'name' => 'Branch B', 'active' => true,
        ]);
        $user = User::factory()->create();
        $user->organizations()->attach($organization, ['role' => 'admin']);
        $user->branches()->attach([$branchA->id, $branchB->id]);
        $orderB = DB::table('orders')->insertGetId([
            'organization_id' => $organization->id, 'branch_id' => $branchB->id,
            'customer_name' => 'Branch B Customer', 'status' => 'draft',
            'total' => '100.00', 'paid_total' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user)->withHeaders([
            'X-Organization-ID' => $organization->id,
            'X-Branch-ID' => $branchA->id,
            'Idempotency-Key' => 'cross-branch',
        ]);

        $this->postJson("/api/v1/orders/{$orderB}/transitions", ['status' => 'cancelled'])->assertForbidden();
        $this->postJson('/api/v1/payments', [
            'order_id' => $orderB, 'amount' => '10.00', 'method' => 'cash',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $orderB, 'status' => 'draft', 'paid_total' => 0]);
    }

    /** @return array{Organization, Branch} */
    private function tenant(string $suffix): array
    {
        $organization = Organization::create(['name' => "Organization {$suffix}"]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => "Branch {$suffix}",
            'active' => true,
        ]);

        return [$organization, $branch];
    }
}
