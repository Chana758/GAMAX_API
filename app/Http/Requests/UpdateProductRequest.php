<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $image   = StoreProductRequest::IMAGE_RULE;

        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'series'      => ['sometimes', 'required', 'string', 'max:255'],
            'brand_id'    => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'brand'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'slug'        => ['sometimes', 'required', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product)],
            'description' => ['sometimes', 'nullable', 'string'],
            'image'       => ['sometimes', 'nullable', 'string', 'max:255', $image],
            // Sending "images" replaces the whole gallery (send [] to remove all)
            'images'      => ['sometimes', 'array', 'max:8'],
            'images.*'    => ['required', 'string', 'max:255', 'distinct', $image],
            'is_active'   => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique'        => 'This slug is already in use.',
            'category_id.exists' => 'The selected category does not exist.',
            'brand_id.exists'    => 'The selected brand does not exist.',
            'image.regex'        => 'The image path is invalid. Upload the image first via /admin/uploads/image.',
            'images.*.regex'     => 'A gallery image path is invalid. Upload it first via /admin/uploads/image.',
            'images.max'         => 'A product can have at most 8 gallery images.',
        ];
    }
}
