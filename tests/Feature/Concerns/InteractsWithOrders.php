<?php

namespace Tests\Feature\Concerns;

use App\Models\Address;
use App\Models\Product;
use App\Models\User;

/** Helpers for the address and order tests (use together with InteractsWithCatalog). */
trait InteractsWithOrders
{
    protected function makeAddress(User $user, array $attributes = []): Address
    {
        return $user->addresses()->create($attributes + [
            'label'      => 'Home',
            'full_name'  => 'Sok Dara',
            'phone'      => '012345678',
            'province'   => 'Phnom Penh',
            'address'    => '#12, St 271, Sangkat Tuol Tumpung, Khan Chamkarmon',
            'is_default' => true,
        ]);
    }

    /** [[variant_id, quantity], ...] -> request body for /orders and /orders/quote */
    protected function checkout(int $addressId, array $lines, array $extra = []): array
    {
        return $extra + [
            'address_id'     => $addressId,
            'payment_method' => 'cod',
            'items'          => array_map(fn ($l) => ['variant_id' => $l[0], 'quantity' => $l[1]], $lines),
        ];
    }

    protected function firstVariantId(Product $product): int
    {
        return $product->variants()->orderBy('id')->value('id');
    }
}
