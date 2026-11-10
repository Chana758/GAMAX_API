<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'product_id'    => $this->product_id,
            'variant_id'    => $this->product_variant_id,
            // Current slug, for "view product" / "buy again" links
            'product_slug'  => $this->whenLoaded('product', fn () => $this->product?->slug),
            'product_name'  => $this->product_name,
            'variant_title' => $this->variant_title,
            'sku'           => $this->sku,
            'config'        => $this->config,
            'image'         => $this->image,
            'image_url'     => $this->image ? url($this->image) : null,
            'unit_price'    => (float) $this->unit_price,
            'quantity'      => $this->quantity,
            'line_total'    => (float) $this->line_total,
        ];
    }
}
