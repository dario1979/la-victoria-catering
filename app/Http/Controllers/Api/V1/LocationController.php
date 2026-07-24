<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveLocationRequest;
use App\Models\Location;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LocationController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ServerDataTable $table): Response
    {
        return $table->respond(
            Location::query()
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id),
            $request,
            ['name'],
            ['id' => 'id', 'name' => 'name', 'created_at' => 'created_at'],
            ['active' => 'active'],
            ['ID' => 'id', 'Nombre' => 'name', 'Activo' => 'active', 'Creado' => 'created_at'],
            'ubicaciones',
            'name',
            'asc',
        );
    }

    public function store(SaveLocationRequest $request, TenantContext $tenant): JsonResponse
    {
        abort_unless($tenant->can('owner', 'admin', 'inventory'), 403);
        $location = Location::create([
            ...$request->validated(),
            'organization_id' => $tenant->organization->id,
            'branch_id' => $tenant->branch->id,
        ]);

        return response()->json(['data' => $location], 201);
    }
}
