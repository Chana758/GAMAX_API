<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVariantRequest extends FormRequest
{
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
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'This SKU is already in use.',
        ];
    }
}