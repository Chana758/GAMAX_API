<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreProductRequest extends FormRequest
{
    /** Only files uploaded through POST /admin/uploads/image are accepted. */
    public const IMAGE_RULE = 'regex:#^/storage/products/[\w-]+\.(jpe?g|png|webp)$#i';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name') && ! $this->filled('slug')) {
            $this->merge(['slug' => Str::slug($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'series'      => ['required', 'string', 'max:255'],
            // Send either brand_id (existing brand) or brand (name: found or created automatically)
            'brand_id'    => ['nullable', 'integer', 'exists:brands,id'],
            'brand'       => ['nullable', 'string', 'max:100'],
            'name'        => ['required', 'string', 'max:255'],
            'slug'        => ['required', 'string', 'max:255', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'image'       => ['nullable', 'string', 'max:255', self::IMAGE_RULE],
            'images'      => ['sometimes', 'array', 'max:8'],
            'images.*'    => ['required', 'string', 'max:255', 'distinct', self::IMAGE_RULE],
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
