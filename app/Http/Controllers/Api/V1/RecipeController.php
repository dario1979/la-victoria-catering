<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Production\RecipeManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveRecipeRequest;
use App\Models\Recipe;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecipeController extends Controller
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $recipes = Recipe::query()->whereHas('product', fn ($query) => $query
            ->where('organization_id', $tenant->organization->id))
            ->when($request->filled('product_id'), fn ($query) => $query
                ->where('product_id', $request->integer('product_id')))
            ->with(['product', 'items.ingredient'])->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($recipes);
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
