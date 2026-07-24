<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\AlertManager;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AlertController extends Controller
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $alerts = Alert::query()
            ->where('organization_id', $tenant->organization->id)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $tenant->branch->id))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->query('severity')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('event'), fn ($query) => $query->where('event', $request->query('event')))
            ->latest('last_seen_at')->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($alerts);
    }

    public function show(Alert $alert, TenantContext $tenant): JsonResponse
    {
        $this->assertTenant($alert, $tenant);
        if (! $alert->read_at) {
            $alert->update(['read_at' => now()]);
        }

        return response()->json(['data' => $alert->fresh()]);
    }

    public function acknowledge(Alert $alert, AlertManager $manager, Request $request, TenantContext $tenant): JsonResponse
    {
        $this->assertTenant($alert, $tenant);
        abort_unless($tenant->can('owner', 'admin', 'inventory', 'production', 'finance', 'purchasing'), 403);
        $manager->acknowledge($alert->id, $request->user()->id);

        return response()->json(['data' => $alert->fresh()]);
    }

    public function resolve(Alert $alert, AlertManager $manager, Request $request, TenantContext $tenant): JsonResponse
    {
        $this->assertTenant($alert, $tenant);
        abort_unless($tenant->can('owner', 'admin', 'inventory', 'production', 'finance'), 403);
        $manager->resolve($alert->id, $request->user()->id);

        return response()->json(['data' => $alert->fresh()]);
    }

    private function assertTenant(Alert $alert, TenantContext $tenant): void
    {
        abort_unless(
            $alert->organization_id === $tenant->organization->id
            && ($alert->branch_id === null || $alert->branch_id === $tenant->branch->id),
            404
        );
    }
}
