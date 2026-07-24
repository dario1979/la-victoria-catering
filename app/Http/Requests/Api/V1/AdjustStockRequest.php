<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
            'product_id' => ['required_without:lot_id', 'integer', 'exists:products,id'],
            'location_id' => ['required_without:lot_id', 'integer', 'exists:locations,id'],
            'code' => ['required_without:lot_id', 'string', 'max:128'],
            'unit' => ['required_without:lot_id', Rule::in(['unit', 'kg', 'g', 'l', 'ml'])],
            'expires_at' => ['nullable', 'date'],
            'quantity' => ['required', 'decimal:0,3', 'not_in:0,0.0,0.00,0.000'],
            'reason' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['receipt', 'adjustment', 'waste', 'reversal'])],
        ];
    }
}
