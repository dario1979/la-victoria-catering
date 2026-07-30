<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Procurement\ProcurementAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveSupplierRequest;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class SupplierController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenant,
        ProcurementAccess $access,
        ServerDataTable $table,
    ): Response {
        $access->authorize($tenant, 'view-suppliers');

        return $table->respond(
            Supplier::query()->where('organization_id', $tenant->organization->id),
            $request,
            ['trade_name', 'legal_name', 'tax_id', 'email', 'contact_name'],
            ['id' => 'id', 'trade_name' => 'trade_name', 'lead_time_days' => 'lead_time_days', 'created_at' => 'created_at'],
            ['active' => 'active'],
            [
                'ID' => 'id', 'Nombre comercial' => 'trade_name', 'Razón social' => 'legal_name',
                'CUIT' => 'tax_id', 'Email' => 'email', 'Teléfono' => 'phone',
                'Plazo (días)' => 'lead_time_days', 'Activo' => 'active',
            ],
            'proveedores',
            'trade_name',
            'asc',
        );
    }

    public function store(
        SaveSupplierRequest $request,
        TenantContext $tenant,
        ProcurementAccess $access,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-suppliers');
        $supplier = Supplier::create([
            ...$request->validated(),
            'organization_id' => $tenant->organization->id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $supplier], 201);
    }

    public function show(
        Supplier $supplier,
        TenantContext $tenant,
        ProcurementAccess $access,
    ): JsonResponse {
        $access->authorize($tenant, 'view-suppliers');
        $this->assertTenant($supplier, $tenant);

        return response()->json(['data' => $supplier->load(['products.product', 'products.prices'])]);
    }

    public function update(
        SaveSupplierRequest $request,
        Supplier $supplier,
        TenantContext $tenant,
        ProcurementAccess $access,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-suppliers');
        $this->assertTenant($supplier, $tenant);
        if (($request->validated('active') === false) && $this->hasOpenOrders($supplier)) {
            throw ValidationException::withMessages(['active' => ['A supplier with open purchase orders cannot be deactivated.']]);
        }
        $supplier->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return response()->json(['data' => $supplier->fresh()]);
    }

    public function setActive(
        Request $request,
        Supplier $supplier,
        TenantContext $tenant,
        ProcurementAccess $access,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-suppliers');
        $this->assertTenant($supplier, $tenant);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        if (! $data['active'] && $this->hasOpenOrders($supplier)) {
            throw ValidationException::withMessages(['active' => ['A supplier with open purchase orders cannot be deactivated.']]);
        }
        $supplier->update(['active' => $data['active'], 'updated_by' => $request->user()->id]);

        return response()->json(['data' => $supplier->fresh()]);
    }

    private function assertTenant(Supplier $supplier, TenantContext $tenant): void
    {
        abort_unless($supplier->organization_id === $tenant->organization->id, 404);
    }

    private function hasOpenOrders(Supplier $supplier): bool
    {
        return PurchaseOrder::query()->where('supplier_id', $supplier->id)
            ->whereNotIn('status', ['received', 'cancelled'])->exists();
    }
}
