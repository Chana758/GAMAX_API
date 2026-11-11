<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;

class AdminOrderResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'admin_note'    => $this->admin_note,
            'next_statuses' => Order::NEXT_STATUSES[$this->status] ?? [],
            'channel'       => $this->channel,
            'fulfillment'   => $this->fulfillment,
            'payments'      => PaymentResource::collection($this->whenLoaded('payments')),
            'created_by'    => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'customer'      => $this->whenLoaded('user', fn () => $this->user ? [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ] : null),
        ];
    }
}
