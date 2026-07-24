<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\AlertManager;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AlertController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ServerDataTable $table): Response
    {
        return $table->respond(
            Alert::query()
                ->where('organization_id', $tenant->organization->id)
                ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $tenant->branch->id)),
            $request,
            ['event', 'condition', 'expected_action'],
            ['id' => 'id', 'event' => 'event', 'severity' => 'severity', 'status' => 'status', 'last_seen_at' => 'last_seen_at'],
            ['severity' => 'severity', 'status' => 'status', 'event' => 'event'],
            ['ID' => 'id', 'Evento' => 'event', 'Condición' => 'condition', 'Severidad' => 'severity', 'Estado' => 'status', 'Acción esperada' => 'expected_action', 'Última detección' => 'last_seen_at'],
            'alertas',
            'last_seen_at',
        );
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
