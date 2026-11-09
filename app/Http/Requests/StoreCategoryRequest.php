<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Override;

class StoreCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if($this->name && !$this->slug){
            $this->merge([
                'slug' =>Str::slug($this->name),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name'       => ['required','string','max:255'],
            'slug'       => ['required','string','max:255','unique:categories,slug'],
            'icon'       => ['nullable','string','max:255'],
            'sort_order' => ['nullable','integer','min:0']

        ];
    }
    public function messages(): array
    {
        return [
            'slug.unique' => 'This slug is already in use.',
        ];
    }
}
