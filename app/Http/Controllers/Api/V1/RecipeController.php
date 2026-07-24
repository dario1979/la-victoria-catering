<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Production\RecipeManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveRecipeRequest;
use App\Models\Recipe;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RecipeController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ServerDataTable $table): Response
    {
        return $table->respond(
            Recipe::query()->whereHas('product', fn ($query) => $query
                ->where('organization_id', $tenant->organization->id))
                ->with(['product', 'items.ingredient']),
            $request,
            [],
            ['id' => 'id', 'version' => 'version', 'status' => 'status', 'yield_quantity' => 'yield_quantity', 'created_at' => 'created_at'],
            ['product_id' => 'product_id', 'status' => 'status'],
            ['ID' => 'id', 'Producto' => 'product.name', 'Versión' => 'version', 'Estado' => 'status', 'Rendimiento' => 'yield_quantity', 'Unidad' => 'yield_unit', 'Creada' => 'created_at'],
            'recetas',
        );
    }

    public function store(
        SaveRecipeRequest $request,
        RecipeManager $recipes,
        TenantContext $tenant,
    ): JsonResponse {
        abort_unless($tenant->can('owner', 'admin', 'production'), 403);

        return response()->json(['data' => $recipes->create($request->validated(), $tenant)], 201);
    }

    public function show(Recipe $recipe, TenantContext $tenant): JsonResponse
    {
        $this->assertTenant($recipe, $tenant);

        return response()->json(['data' => $recipe->load(['product', 'items.ingredient'])]);
    }

    public function update(
        SaveRecipeRequest $request,
        Recipe $recipe,
        RecipeManager $recipes,
        TenantContext $tenant,
    ): JsonResponse {
        abort_unless($tenant->can('owner', 'admin', 'production'), 403);
        $this->assertTenant($recipe, $tenant);

        return response()->json([
            'data' => $recipes->changeStatus($recipe, $request->validated('status'), $tenant),
        ]);
    }

    private function assertTenant(Recipe $recipe, TenantContext $tenant): void
    {
        abort_unless($recipe->product()->where('organization_id', $tenant->organization->id)->exists(), 404);
    }
}
