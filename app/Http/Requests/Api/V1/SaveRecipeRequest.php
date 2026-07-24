<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return ['status' => ['required', Rule::in(['approved', 'inactive'])]];
        }

        return [
            'product_id' => ['required', 'integer'],
            'expected_yield' => ['required', 'decimal:0,3', 'gt:0'],
            'yield_unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'unit'])],
            'theoretical_waste_percent' => ['nullable', 'decimal:0,2', 'between:0,100'],
            'status' => ['sometimes', Rule::in(['draft', 'approved'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'items.*.unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'unit'])],
        ];
    }
}
