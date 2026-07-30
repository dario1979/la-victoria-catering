<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Procurement\ProcurementAccess;
use App\Domain\Procurement\SupplierCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveSupplierProductRequest;
use App\Models\SupplierProduct;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SupplierProductController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenant,
        ProcurementAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view-suppliers');

        return $table->respond(
            SupplierProduct::query()->where('organization_id', $tenant->organization->id)
                ->with(['supplier', 'product', 'prices']),
            $request,
            [
                fn ($query, $search, $operator) => $query
                    ->orWhere('supplier_code', $operator, "%{$search}%")
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('trade_name', $operator, "%{$search}%"))
                    ->orWhereHas('product', fn ($product) => $product->where('name', $operator, "%{$search}%")),
            ],
            ['id' => 'id', 'purchase_unit' => 'purchase_unit', 'lead_time_days' => 'lead_time_days', 'created_at' => 'created_at'],
            ['supplier_id' => 'supplier_id', 'product_id' => 'product_id', 'active' => 'active', 'preferred' => 'preferred'],
            [
                'ID' => 'id', 'Proveedor' => fn ($row) => $row->supplier->trade_name,
                'Producto' => fn ($row) => $row->product->name, 'Código proveedor' => 'supplier_code',
                'Unidad compra' => 'purchase_unit', 'Factor' => 'conversion_factor',
                'Mínimo' => 'minimum_quantity',
                'Precio vigente' => fn ($row) => $row->current_price['price'] ?? null,
                'Moneda' => fn ($row) => $row->current_price['currency'] ?? null,
                'Preferido' => 'preferred', 'Activo' => 'active',
            ],
            'catalogo-proveedores',
        );
    }

    public function store(
        SaveSupplierProductRequest $request,
        TenantContext $tenant,
        ProcurementAccess $access,
        SupplierCatalog $catalog,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-suppliers');
        $item = $catalog->save(
            $request->validated(), $tenant->organization->id, $request->user()->id
        );

        return response()->json(['data' => $item], 201);
    }

    public function show(
        SupplierProduct $supplierProduct,
        TenantContext $tenant,
        ProcurementAccess $access,
    ): JsonResponse {
        $access->authorize($tenant, 'view-suppliers');
        $this->assertTenant($supplierProduct, $tenant);

        return response()->json(['data' => $supplierProduct->load(['supplier', 'product', 'prices'])]);
    }

    public function update(
        SaveSupplierProductRequest $request,
        SupplierProduct $supplierProduct,
        TenantContext $tenant,
        ProcurementAccess $access,
        SupplierCatalog $catalog,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-suppliers');
        $this->assertTenant($supplierProduct, $tenant);
        $item = $catalog->save(
            $request->validated(), $tenant->organization->id, $request->user()->id, $supplierProduct
        );

        return response()->json(['data' => $item]);
    }

    private function assertTenant(SupplierProduct $item, TenantContext $tenant): void
    {
        abort_unless($item->organization_id === $tenant->organization->id, 404);
    }
}
