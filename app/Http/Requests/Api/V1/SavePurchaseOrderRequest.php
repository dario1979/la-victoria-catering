<?php

namespace App\Http\Requests\Api\V1;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(TenantContext::class)->can('owner', 'admin', 'purchasing');
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class);

        return [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('organization_id', $tenant->organization->id)->where('active', true)],
            'ordered_at' => ['required', 'date'],
            'expected_at' => ['nullable', 'date', 'after_or_equal:ordered_at'],
            'currency' => ['required', Rule::in(['ARS', 'USD'])],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.supplier_product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:99999999999.999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'decimal:0,2', 'gte:0', 'max:999999999999.99'],
            'items.*.tax_amount' => ['sometimes', 'numeric', 'decimal:0,2', 'gte:0', 'max:999999999999.99'],
        ];
    }
}
