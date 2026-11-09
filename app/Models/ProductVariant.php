<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'title',
        'config',
        'sku',
        'price',
        'compare_price',
        'in_stock',
        'stock_quantity',
        'is_active',     // false = hidden from the store (kept for order history)
    ];

    protected $casts = [
        'config'         => 'array',
        'in_stock'       => 'boolean',
        'is_active'      => 'boolean',
        'price'          => 'decimal:2',
        'compare_price'  => 'decimal:2',
        'stock_quantity' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Variants shown in the store. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Options in a form that ignores letter case, spaces, a trailing ":" and key order,
     * so {"RAM": "16GB"} and {"ram:": "16gb"} count as the same options.
     */
    public static function canonicalConfig(?array $config): string
    {
        $normal = [];
        foreach ($config ?? [] as $key => $value) {
            $key = Str::of((string) $key)->trim()->rtrim(':')->trim()->lower()->replaceMatches('/\s+/', '_')->value();
            $normal[$key] = is_scalar($value) ? Str::lower(trim((string) $value)) : $value;
        }
        ksort($normal);

        return json_encode($normal, JSON_UNESCAPED_UNICODE);
    }
}
