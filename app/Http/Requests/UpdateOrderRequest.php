<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff: PATCH /admin/orders/{number}. Allowed status moves are checked in OrderService.
 * Money is recorded with POST /admin/orders/{number}/payments, not here.
 * Contact and address can be corrected until the order ships (the province can't change:
 * it sets the delivery fee).
 */
class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order'));
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) ? trim(preg_replace('/\s+/', ' ', $v)) : $v;
        $merge = [];
        foreach (['ship_name', 'ship_email', 'ship_address'] as $key) {
            if ($this->has($key)) {
                $merge[$key] = $clean($this->input($key));
            }
        }
        if ($this->has('ship_phone') && is_string($this->input('ship_phone'))) {
            $merge['ship_phone'] = preg_replace('/[\s\-().]/', '', $this->input('ship_phone'));
        }
        if (array_key_exists('ship_email', $merge) && $merge['ship_email'] === '') {
            $merge['ship_email'] = null;
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'ship_name'       => ['sometimes', 'required', 'string', 'max:100'],
            'ship_phone'      => ['sometimes', 'required', 'string', 'regex:/^(\+855|0)\d{8,9}$/'],
            'ship_email'      => ['sometimes', 'nullable', 'email', 'max:255'],
            'ship_address'    => ['sometimes', 'required', 'string', 'min:10', 'max:500'],
            'status'          => ['sometimes', 'string', Rule::in(Order::STATUSES)],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'admin_note'      => ['sometimes', 'nullable', 'string', 'max:1000'],
            'cancel_reason'   => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'ship_phone.regex' => 'Enter a valid Cambodian phone number (e.g. 012 345 678).',
            'ship_address.min' => 'Please enter a detailed address (house, street, sangkat, khan).',
        ];
    }
}
