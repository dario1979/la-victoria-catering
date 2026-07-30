<?php

namespace App\Http\Requests\Api\V1;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(TenantContext::class)->can('owner', 'admin', 'purchasing');
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class);

        return [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $tenant->organization->id)],
            'product_id' => ['required', Rule::exists('products', 'id')->where('organization_id', $tenant->organization->id)],
            'supplier_code' => ['nullable', 'string', 'max:255'],
            'purchase_unit' => ['required', Rule::in(['g', 'kg', 'ml', 'l', 'unit'])],
            'conversion_factor' => ['required', 'numeric', 'decimal:0,6', 'gt:0', 'max:999999999.999999'],
            'minimum_quantity' => ['required', 'numeric', 'decimal:0,3', 'gte:0', 'max:99999999999.999'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'preferred' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'gte:0', 'max:999999999999.99'],
            'currency' => ['required', Rule::in(['ARS', 'USD'])],
            'price_valid_from' => ['required', 'date'],
        ];
    }
}
