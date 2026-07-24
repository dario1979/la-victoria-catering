<?php

namespace App\Domain\Production;

use App\Models\Product;
use App\Models\Recipe;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecipeManager
{
    public function __construct(private readonly UnitConverter $units) {}

    public function create(array $data, TenantContext $tenant): Recipe
    {
        return DB::transaction(function () use ($data, $tenant): Recipe {
            $product = Product::query()->where('organization_id', $tenant->organization->id)
                ->where('active', true)->lockForUpdate()->findOrFail($data['product_id']);
            $this->units->assertCompatible($data['yield_unit'], $product->unit);
            $ingredientIds = collect($data['items'])->pluck('ingredient_product_id');
            if ($ingredientIds->contains($product->id) || $ingredientIds->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['Ingredients must be unique and cannot be the elaborated product.'],
                ]);
            }
            $ingredients = Product::query()->where('organization_id', $tenant->organization->id)
                ->where('active', true)->whereIn('id', $ingredientIds)->get()->keyBy('id');
            if ($ingredients->count() !== $ingredientIds->count()) {
                throw ValidationException::withMessages(['items' => ['Every ingredient must belong to the active organization.']]);
            }
            foreach ($data['items'] as $item) {
                $this->units->assertCompatible($item['unit'], $ingredients[$item['ingredient_product_id']]->unit);
            }
            $version = ((int) Recipe::query()->where('product_id', $product->id)->max('version')) + 1;
            $status = $data['status'] ?? 'approved';
            $recipe = Recipe::create([
                'product_id' => $product->id,
                'version' => $version,
                'expected_yield' => $data['expected_yield'],
                'yield_unit' => $data['yield_unit'],
                'theoretical_waste_percent' => $data['theoretical_waste_percent'] ?? null,
                'status' => $status,
                'approved_at' => $status === 'approved' ? now() : null,
            ]);
            $recipe->items()->createMany($data['items']);

            return $recipe->load(['product', 'items.ingredient']);
        });
    }

    public function changeStatus(Recipe $recipe, string $status, TenantContext $tenant): Recipe
    {
        abort_unless($recipe->product()->where('organization_id', $tenant->organization->id)->exists(), 404);
        if ($recipe->status === 'inactive' && $status === 'approved') {
            throw ValidationException::withMessages([
                'status' => ['An inactive recipe version cannot be reactivated; create a new version.'],
            ]);
        }
        $recipe->update([
            'status' => $status,
            'approved_at' => $status === 'approved' ? ($recipe->approved_at ?? now()) : $recipe->approved_at,
        ]);

        return $recipe->fresh(['product', 'items.ingredient']);
    }

    public function snapshot(Recipe $recipe): array
    {
        $recipe->loadMissing(['product', 'items.ingredient']);

        return [
            'recipe_id' => $recipe->id,
            'version' => $recipe->version,
            'product' => [
                'id' => $recipe->product->id,
                'name' => $recipe->product->name,
                'unit' => $recipe->product->unit,
            ],
            'expected_yield' => $recipe->getRawOriginal('expected_yield'),
            'yield_unit' => $recipe->yield_unit,
            'theoretical_waste_percent' => $recipe->getRawOriginal('theoretical_waste_percent'),
            'items' => $recipe->items->values()->map(fn ($item, int $index) => [
                'index' => $index,
                'ingredient_product_id' => $item->ingredient_product_id,
                'ingredient_name' => $item->ingredient->name,
                'product_unit' => $item->ingredient->unit,
                'quantity' => $item->getRawOriginal('quantity'),
                'unit' => $item->unit,
            ])->all(),
        ];
    }
}
