<?php

namespace App\Http\Resources;

use App\Support\ShopRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** An order as the customer sees it. AdminOrderResource adds staff-only fields. */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'number'          => $this->number,
            'status'          => $this->status,
            'payment_method'  => $this->payment_method,
            'payment_status'  => $this->payment_status,
            'subtotal'        => (float) $this->subtotal,
            'discount'        => (float) $this->discount,
            'shipping_fee'    => (float) $this->shipping_fee,
            'cod_fee'         => (float) $this->cod_fee,
            'total'           => (float) $this->total,
            'item_count'      => $this->relationLoaded('items')
                ? (int) $this->items->sum('quantity')
                : (int) ($this->items_sum_quantity ?? 0),
            'shipping'        => [
                'name'     => $this->ship_name,
                'phone'    => $this->ship_phone,
                'email'    => $this->ship_email,
                'province' => $this->ship_province,
                'address'  => $this->ship_address,
            ],
            'delivery_days'   => ShopRules::hasProvince($this->ship_province)
                ? ShopRules::zone($this->ship_province)['delivery_days']
                : null,
            'note'            => $this->note,
            'tracking_number' => $this->tracking_number,
            'cancel_reason'   => $this->cancel_reason,
            'cancelled_by'    => $this->cancelled_by,
            'can_cancel'      => $this->canBeCancelledByCustomer(),
            'created_at'      => $this->created_at?->toIso8601String(),
            'confirmed_at'    => $this->confirmed_at?->toIso8601String(),
            'shipped_at'      => $this->shipped_at?->toIso8601String(),
            'delivered_at'    => $this->delivered_at?->toIso8601String(),
            'cancelled_at'    => $this->cancelled_at?->toIso8601String(),
            'items'           => OrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
