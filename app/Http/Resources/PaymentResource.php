<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One payment or refund row (admin only). */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'type'            => $this->type,
            'method'          => $this->method,
            'provider'        => $this->provider,
            'reference'       => $this->reference,
            'amount'          => (float) $this->amount,             // USD counted for the order
            'currency'        => $this->currency,                    // currency received
            'amount_received' => (float) $this->amount_received,     // in that currency
            'exchange_rate'   => $this->exchange_rate !== null ? (float) $this->exchange_rate : null,
            'source'          => $this->source,
            // Where the money came from (not who owns the order)
            'payer_account'   => $this->payer_account,
            'payer_name'      => $this->payer_name,
            'note'            => $this->note,
            'recorded_by'     => $this->whenLoaded('recorder', fn () => $this->recorder ? [
                'id'   => $this->recorder->id,
                'name' => $this->recorder->name,
            ] : null),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
