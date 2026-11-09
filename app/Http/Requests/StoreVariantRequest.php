<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksDuplicateVariants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVariantRequest extends FormRequest
{
    use ChecksDuplicateVariants;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'    => ['nullable', 'string', 'max:255'],
            'config'   => ['present', 'array'],
            'compare_price'  => ['nullable', 'numeric', 'gt:price', 'max:99999999.99'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'sku'      => ['nullable', 'string', 'max:255', 'unique:product_variants,sku'],
            'price'    => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'in_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Runs after the rules above pass. */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkDuplicates($validator, $this->route('product')->id)];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'This SKU is already in use.',
        ];
    }
}