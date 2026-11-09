<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksDuplicateVariants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateVariantRequest extends FormRequest
{
    use ChecksDuplicateVariants;

    public function authorize(): bool
    {
        return true; // Authorization is handled by ProductVariantPolicy in the controller.
    }

    public function rules(): array
    {
        $variant = $this->route('variant');

        return [
            // nullable: send null/"" to make the service regenerate the title from config
            'title'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'config'   => ['sometimes', 'present', 'array'],
            'compare_price' => [
                'sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99',
                // Same rule as StoreVariantRequest (gt:price), using the new price
                // if it was sent, otherwise the price already saved.
                function (string $attribute, mixed $value, \Closure $fail) use ($variant) {
                    $price = $this->input('price', $variant?->price);

                    if ($value !== null && $value !== '' && (float) $value <= (float) $price) {
                        $fail('The compare-at price must be higher than the price.');
                    }
                },
            ],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'sku'      => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($variant)],
            'price'    => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'in_stock' => ['sometimes', 'boolean'],
            // false = hide from the store (use this for variants that were already ordered)
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Runs after the rules above pass. */
    public function after(): array
    {
        $variant = $this->route('variant');

        return [fn (Validator $validator) => $this->checkDuplicates($validator, $variant->product_id, $variant)];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'This SKU is already in use.',
        ];
    }
}