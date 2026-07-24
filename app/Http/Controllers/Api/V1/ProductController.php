<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveProductRequest;
use App\Models\Product;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProductController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ServerDataTable $table): Response
    {
        return $table->respond(
            Product::query()->where('organization_id', $tenant->organization->id),
            $request,
            ['name', 'type', 'unit'],
            ['id' => 'id', 'name' => 'name', 'type' => 'type', 'price' => 'price', 'created_at' => 'created_at'],
            ['active' => 'active', 'type' => 'type', 'unit' => 'unit'],
            ['ID' => 'id', 'Nombre' => 'name', 'Tipo' => 'type', 'Unidad' => 'unit', 'Precio' => 'price', 'Stock mínimo' => 'minimum_stock', 'Activo' => 'active'],
            'productos',
            'name',
            'asc',
        );
    }

    public function store(SaveProductRequest $request, TenantContext $tenant): JsonResponse
    {
        abort_unless($tenant->can('owner', 'admin', 'inventory'), 403);
        $product = Product::create([
            ...$request->validated(), 'organization_id' => $tenant->organization->id,
        ]);

        return response()->json(['data' => $product], 201);
    }

    public function show(Product $product, TenantContext $tenant): JsonResponse
    {
        $this->assertTenant($product, $tenant);

        return response()->json(['data' => $product]);
    }

    public function update(SaveProductRequest $request, Product $product, TenantContext $tenant): JsonResponse
    {
        $this->assertTenant($product, $tenant);
        abort_unless($tenant->can('owner', 'admin', 'inventory'), 403);
        $product->update($request->validated());

        return response()->json(['data' => $product->fresh()]);
    }

    private function assertTenant(Product $product, TenantContext $tenant): void
    {
        abort_unless($product->organization_id === $tenant->organization->id, 404);
    }
}
