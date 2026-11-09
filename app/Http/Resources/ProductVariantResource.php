<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'product_id'       => $this->product_id,
            'title'            => $this->title,
            'config'           => $this->config,
            'sku'              => $this->sku,
            'price'            => (float) $this->price,
            'compare_price'    => $this->compare_price ? (float) $this->compare_price : null,
            'discount_percent' => $this->compare_price && $this->compare_price > $this->price
                ? (int) round((1 - $this->price / $this->compare_price) * 100)
                : null,
            'in_stock'         => $this->in_stock,
            'stock_quantity'   => $this->stock_quantity,
        ];
    }
}