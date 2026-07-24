<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['raw_material', 'semi_finished', 'finished_product', 'packaging'])],
            'unit' => ['required', Rule::in(['unit', 'kg', 'g', 'l', 'ml'])],
            'minimum_stock' => ['required', 'decimal:0,3', 'gte:0'],
            'price' => ['required', 'decimal:0,2', 'gte:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
