<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Staff: PATCH /admin/orders/{number}. Allowed status moves are checked in OrderService. */
class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order'));
    }

    public function rules(): array
    {
        return [
            'status'          => ['sometimes', 'string', Rule::in(Order::STATUSES)],
            'payment_status'  => ['sometimes', 'string', Rule::in(Order::PAYMENT_STATUSES)],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'admin_note'      => ['sometimes', 'nullable', 'string', 'max:1000'],
            'cancel_reason'   => ['nullable', 'string', 'max:500'],
        ];
    }
}
