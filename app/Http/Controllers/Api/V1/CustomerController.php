<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveCustomerRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Decimal;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomerController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ServerDataTable $table): Response
    {
        abort_unless($tenant->can('owner', 'admin', 'sales', 'finance'), 403);

        return $table->respond(
            Customer::query()->where('organization_id', $tenant->organization->id),
            $request,
            ['name', 'tax_id', 'email', 'phone'],
            ['id' => 'id', 'name' => 'name', 'email' => 'email', 'created_at' => 'created_at'],
            ['active' => 'active', 'tax_condition' => 'tax_condition'],
            ['ID' => 'id', 'Nombre' => 'name', 'CUIT' => 'tax_id', 'Correo' => 'email', 'Teléfono' => 'phone', 'Activo' => 'active'],
            'clientes',
            'name',
            'asc',
        );
    }

    public function store(SaveCustomerRequest $request, TenantContext $tenant): JsonResponse
    {
        abort_unless($tenant->can('owner', 'admin', 'sales'), 403);
        $customer = Customer::create([
            ...$request->validated(), 'organization_id' => $tenant->organization->id,
        ]);

        return response()->json(['data' => $customer], 201);
    }

    public function show(Customer $customer, TenantContext $tenant): JsonResponse
    {
        $this->assertTenant($customer, $tenant);

        $balance = Order::query()
            ->where('organization_id', $tenant->organization->id)
            ->where('customer_id', $customer->id)
            ->get(['total', 'paid_total'])
            ->sum(fn ($order) => Decimal::toScaledInt($order->getRawOriginal('total'), 2)
                - Decimal::toScaledInt($order->getRawOriginal('paid_total'), 2));

        return response()->json([
            'data' => $customer, 'current_balance' => Decimal::fromScaledInt($balance, 2),
        ]);
    }

    public function update(
        SaveCustomerRequest $request,
        Customer $customer,
        TenantContext $tenant,
    ): JsonResponse {
        $this->assertTenant($customer, $tenant);
        abort_unless($tenant->can('owner', 'admin', 'sales'), 403);
        $customer->update($request->validated());

        return response()->json(['data' => $customer->fresh()]);
    }

    private function assertTenant(Customer $customer, TenantContext $tenant): void
    {
        abort_unless($customer->organization_id === $tenant->organization->id, 404);
        abort_unless($tenant->can('owner', 'admin', 'sales', 'finance'), 403);
    }
}
