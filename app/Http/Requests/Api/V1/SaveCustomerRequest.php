<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('organization')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:32',
                Rule::unique('customers')->where('organization_id', $organizationId)->ignore($this->route('customer'))],
            'tax_condition' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'addresses' => ['nullable', 'array'],
            'addresses.*.label' => ['required_with:addresses', 'string', 'max:64'],
            'addresses.*.line' => ['required_with:addresses', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'decimal:0,2', 'gte:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
