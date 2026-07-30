<?php

namespace App\Http\Requests\Api\V1;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(TenantContext::class)->can('owner', 'admin', 'purchasing', 'inventory');
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class);

        return [
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.location_id' => [
                'required',
                Rule::exists('locations', 'id')
                    ->where('organization_id', $tenant->organization->id)
                    ->where('branch_id', $tenant->branch->id)
                    ->where('active', true),
            ],
            'items.*.received_quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:99999999999.999'],
            'items.*.accepted_quantity' => ['required', 'numeric', 'decimal:0,3', 'gte:0', 'max:99999999999.999'],
            'items.*.rejected_quantity' => ['required', 'numeric', 'decimal:0,3', 'gte:0', 'max:99999999999.999'],
            'items.*.discrepancy_type' => ['nullable', Rule::in(['shortage', 'excess', 'damaged', 'quality', 'wrong_product', 'other'])],
            'items.*.discrepancy_reason' => ['nullable', 'string', 'max:255'],
            'items.*.lot_code' => ['required', 'string', 'max:255'],
            'items.*.manufactured_at' => ['nullable', 'date', 'before_or_equal:received_at'],
            'items.*.expires_at' => ['nullable', 'date', 'after_or_equal:received_at'],
            'items.*.actual_unit_cost' => ['nullable', 'numeric', 'decimal:0,2', 'gte:0', 'max:999999999999.99'],
        ];
    }
}
