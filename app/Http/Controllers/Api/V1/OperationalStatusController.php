<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\OperationalHealth;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperationalStatusController extends Controller
{
    public function show(
        Request $request,
        TenantContext $tenant,
        OperationalHealth $health,
    ): JsonResponse {
        abort_unless($tenant->can('owner', 'admin'), 403);

        return response()->json([
            'data' => [
                ...$health->diagnostics($tenant),
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }
}
