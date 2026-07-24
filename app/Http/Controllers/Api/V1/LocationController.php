<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveLocationRequest;
use App\Models\Location;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

final class LocationController extends Controller
{
    public function index(TenantContext $tenant): JsonResponse
    {
        $locations = Location::query()
            ->where('organization_id', $tenant->organization->id)
            ->where('branch_id', $tenant->branch->id)
            ->orderBy('name')->get();

        return response()->json(['data' => $locations]);
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
