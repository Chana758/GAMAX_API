<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff: POST /admin/orders/{number}/payments (money received)
 * and    POST /admin/orders/{number}/refunds  (money given back).
 *
 * USD: send amount.  KHR: send amount_received (riel) and exchange_rate (riel per 1 USD,
 * defaults to the shop's rate); the server works out the USD amount and stores the rate.
 */
class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->isRefund() ? 'payment.refund' : 'payment.record');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency'  => strtoupper((string) ($this->input('currency') ?: 'USD')),
            'provider'  => is_string($this->input('provider')) ? strtolower(trim($this->input('provider'))) : $this->input('provider'),
            'reference' => is_string($this->input('reference')) ? (trim($this->input('reference')) ?: null) : $this->input('reference'),
        ]);
    }

    public function rules(): array
    {
        return [
            'method'          => ['required', 'string', Rule::in(Payment::METHODS)],
            'currency'        => ['required', 'string', Rule::in(['USD', 'KHR'])],
            // KHR: ignored, the server converts amount_received with the rate
            'amount'          => ['exclude_if:currency,KHR', 'required', 'numeric', 'min:0.01', 'max:1000000'],
            'amount_received' => ['exclude_unless:currency,KHR', 'required', 'numeric', 'min:100', 'max:10000000000'],
            'exchange_rate'   => ['exclude_unless:currency,KHR', 'nullable', 'numeric', 'min:1000', 'max:10000'], // riel per 1 USD
            // Who issued the reference: bakong, aba, aclb, wing... (default from the method)
            'provider'        => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'],
            'reference'       => ['nullable', 'string', 'max:100'],
            // Where the money came from. Not proof of who owns the order.
            'payer_account'   => ['nullable', 'string', 'max:150'],
            'payer_name'      => ['nullable', 'string', 'max:150'],
            // A refund must say why
            'note'            => [$this->isRefund() ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required'               => 'Write the reason for the refund.',
            'amount.required'             => 'Enter the amount in dollars.',
            'amount_received.required'    => 'Enter the amount received in riel.',
            'exchange_rate.min'           => 'The exchange rate is riel per 1 USD (e.g. 4100).',
            'exchange_rate.max'           => 'The exchange rate is riel per 1 USD (e.g. 4100).',
        ];
    }

    private function isRefund(): bool
    {
        return $this->routeIs('admin.orders.refunds');
    }
}
