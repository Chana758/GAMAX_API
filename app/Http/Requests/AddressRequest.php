<?php

namespace App\Http\Requests;

use App\Models\Address;
use App\Support\ShopRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create (POST) and replace (PUT/PATCH) a saved delivery address. */
class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any logged-in customer; ownership is checked in the controller
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) ? trim(preg_replace('/\s+/', ' ', $v)) : $v;

        $this->merge([
            'label'     => $clean($this->input('label')) ?: 'Home',
            'full_name' => $clean($this->input('full_name')),
            // "012 345-678" -> "012345678"
            'phone'     => is_string($this->input('phone')) ? preg_replace('/[\s\-().]/', '', $this->input('phone')) : $this->input('phone'),
            'email'     => $clean($this->input('email')) ?: null,
            'province'  => $clean($this->input('province')),
            'address'   => $clean($this->input('address')),
        ]);
    }

    public function rules(): array
    {
        return [
            'label'      => ['required', 'string', 'max:30'],
            'full_name'  => ['required', 'string', 'max:100'],
            'phone'      => ['required', 'string', 'regex:/^(\+855|0)\d{8,9}$/'],
            'email'      => ['nullable', 'email', 'max:255'],
            'province'   => ['required', 'string', Rule::in(ShopRules::provinces())],
            'address'    => ['required', 'string', 'min:10', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex'  => 'Enter a valid Cambodian phone number (e.g. 012 345 678).',
            'province.in'  => 'Please choose a province from the list.',
            'address.min'  => 'Please enter a detailed address (house, street, sangkat, khan).',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $user = $this->user();
                $current = $this->route('address');
                $currentId = $current instanceof Address ? $current->id : null;

                if ($currentId === null && $user->addresses()->count() >= (int) config('shop.max_addresses', 10)) {
                    $validator->errors()->add('address', 'You can save up to ' . config('shop.max_addresses', 10) . ' addresses. Remove one first.');
                    return;
                }

                $duplicate = $user->addresses()
                    ->when($currentId, fn ($q) => $q->whereKeyNot($currentId))
                    ->where('phone', $this->input('phone'))
                    ->whereRaw('LOWER(address) = ?', [mb_strtolower($this->input('address'))])
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('address', 'You already saved this address with this phone number.');
                }
            },
        ];
    }
}
