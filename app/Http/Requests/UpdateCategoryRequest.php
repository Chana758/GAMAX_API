<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
class UpdateCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->name && !$this->slug) {
            $this->merge([
                'slug' => Str::slug($this->name),
            ]);
        }
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name'       => ['sometimes', 'required', 'string', 'max:255'],
            'slug'       => ['sometimes','nullable','string','max:255',Rule::unique('categories', 'slug')->ignore($category),],
            'icon'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
}
    public function messages(): array
    {
        return [
            'slug.unique' => 'This slug is already in use.',
        ];
    }
}
