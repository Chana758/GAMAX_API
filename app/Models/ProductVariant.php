<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
];

protected $casts = [
    'config'         => 'array',
    'in_stock'       => 'boolean',
    'price'          => 'decimal:2',
    'compare_price'  => 'decimal:2',
    'stock_quantity' => 'integer',
];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
