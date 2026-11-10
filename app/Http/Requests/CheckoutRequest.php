<?php

namespace App\Http\Requests;

use App\Support\ShopRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /orders/quote and POST /orders.
 * Only ids and quantities are accepted: prices and fees always come from the server.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address_id'         => ['required', 'integer'],
            'payment_method'     => ['required', 'string', Rule::in(ShopRules::paymentMethods())],
            'items'              => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity'   => ['required', 'integer', 'min:1', 'max:' . ShopRules::maxQuantityPerItem()],
            'note'               => ['nullable', 'string', 'max:500'],
            // Total the customer saw; if it changed meanwhile the order is not placed (409)
            'expected_total'     => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method.in' => 'This payment method is not available yet.',
            'items.required'    => 'Your cart is empty.',
            'items.*.quantity.max' => 'You can order at most ' . ShopRules::maxQuantityPerItem() . ' of each item.',
        ];
    }
}
