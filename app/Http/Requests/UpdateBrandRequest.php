<?php

namespace App\Http\Requests;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            $this->merge(['slug' => Brand::slugFor($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name'       => ['sometimes', 'required', 'string', 'max:100'],
            'slug'       => ['sometimes', 'required', 'string', 'max:120', Rule::unique('brands', 'slug')->ignore($this->route('brand'))],
            'logo'       => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^/storage/brands/[\w-]+\.(jpe?g|png|webp)$#i'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'A brand with this name already exists.',
            'logo.regex'  => 'The logo path is invalid. Upload it first via /admin/uploads/image (folder: brands).',
        ];
    }
}
