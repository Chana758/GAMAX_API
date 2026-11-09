<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'category_id'    => $this->category_id,
            'brand_id'       => $this->brand_id,
            'series'         => $this->series,
            // Brand name as a plain string (same shape as before the brands table)
            'brand'          => $this->brand?->name,
            'brand_slug'     => $this->brand?->slug,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'description'    => $this->description,
            'image'          => $this->image,
            'image_url'      => $this->image ? url($this->image) : null,
            // Gallery photos (the cover image is NOT repeated here)
            'images'         => $this->whenLoaded('images', fn () => $this->images->map(fn ($img) => [
                'id'   => $img->id,
                'path' => $img->path,
                'url'  => url($img->path),
            ])->values()),
            'is_active'      => $this->is_active,
            'is_featured'    => $this->is_featured,
            'variants_count' => $this->whenCounted('variants'),
            'category'       => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'variants'       => ProductVariantResource::collection($this->whenLoaded('variants')),
            'price_from'     => $this->whenLoaded('variants', function () {
                $min = $this->variants->min('price');
                return $min !== null ? (float) $min : null;
            }),
            'in_stock'       => $this->whenLoaded('variants', fn () => $this->variants->contains('in_stock', true)),
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
