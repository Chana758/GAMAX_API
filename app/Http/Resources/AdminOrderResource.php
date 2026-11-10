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
            'customer'      => $this->whenLoaded('user', fn () => $this->user ? [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ] : null),
        ];
    }
}
