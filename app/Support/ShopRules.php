<?php

namespace App\Support;

/**
 * Reads config/shop.php. Every price and fee here is in cents (integers),
 * so totals never pick up floating-point rounding errors.
 */
class ShopRules
{
    public static function provinces(): array
    {
        return array_keys(config('shop.provinces', []));
    }

    public static function hasProvince(?string $province): bool
    {
        return $province !== null && array_key_exists($province, config('shop.provinces', []));
    }

    public static function zone(string $province): array
    {
        $zone = config("shop.provinces.{$province}");

        return config("shop.zones.{$zone}", ['shipping_fee' => 0, 'cod_fee' => 0, 'delivery_days' => null]);
    }

    public static function paymentMethods(): array
    {
        return config('shop.payment_methods', ['cod']);
    }

    public static function codAllowed(string $province): bool
    {
        return in_array('cod', self::paymentMethods(), true)
            && ! in_array($province, config('shop.cod_excluded_provinces', []), true);
    }

    public static function freeShippingOverCents(): int
    {
        return self::cents(config('shop.free_shipping_over', 0));
    }

    /** Delivery fee for an order whose items cost $itemsCents. */
    public static function shippingFeeCents(int $itemsCents, string $province, string $paymentMethod = 'cod'): int
    {
        $freeOver = self::freeShippingOverCents();
        if ($freeOver > 0 && $itemsCents >= $freeOver) {
            return 0;
        }
        if ($paymentMethod !== 'cod' && config('shop.free_shipping_for_prepaid', false)) {
            return 0;
        }

        return self::cents(self::zone($province)['shipping_fee']);
    }

    public static function codFeeCents(string $paymentMethod, string $province): int
    {
        return $paymentMethod === 'cod' ? self::cents(self::zone($province)['cod_fee']) : 0;
    }

    public static function codMaxTotalCents(): ?int
    {
        $max = config('shop.cod_max_total');

        return $max === null ? null : self::cents($max);
    }

    public static function maxQuantityPerItem(): int
    {
        return (int) config('shop.max_quantity_per_item', 10);
    }

    /**
     * What the customer must transfer for a KHQR order: [amount, currency].
     * Riel is rounded UP to the next 100 riel (the smallest note in use).
     */
    public static function khqrAmount(int $totalCents): array
    {
        if (self::khqrCurrency() !== 'KHR') {
            return [self::dollars($totalCents), 'USD'];
        }

        $riel = $totalCents * (int) config('shop.khqr.khr_per_usd', 4100) / 100;

        return [(float) (ceil($riel / 100) * 100), 'KHR'];
    }

    public static function khqrCurrency(): string
    {
        return strtoupper((string) config('shop.khqr.currency', 'USD'));
    }

    public static function payWithinHours(): int
    {
        return max((int) config('shop.khqr.pay_within_hours', 24), 1);
    }

    public static function cents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function dollars(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** What the storefront needs to show fees before the customer orders. */
    public static function forStorefront(): array
    {
        return [
            'currency'               => config('shop.currency', 'USD'),
            'free_shipping_over'     => (float) config('shop.free_shipping_over', 0),
            'payment_methods'        => self::paymentMethods(),
            'cod_max_total'          => config('shop.cod_max_total') === null ? null : (float) config('shop.cod_max_total'),
            'max_quantity_per_item'  => self::maxQuantityPerItem(),
            'free_shipping_for_prepaid' => (bool) config('shop.free_shipping_for_prepaid', false),
            'khqr'                   => [
                'account_name'     => config('shop.khqr.account_name'),
                'bank'             => config('shop.khqr.bank'),
                'currency'         => self::khqrCurrency(),
                'khr_per_usd'      => (int) config('shop.khqr.khr_per_usd', 4100),
                'pay_within_hours' => self::payWithinHours(),
            ],
            'provinces'              => collect(self::provinces())->map(function (string $name) {
                $zone = self::zone($name);

                return [
                    'name'          => $name,
                    'zone'          => config("shop.provinces.{$name}"),
                    'shipping_fee'  => (float) $zone['shipping_fee'],
                    'cod_fee'       => (float) $zone['cod_fee'],
                    'cod_allowed'   => self::codAllowed($name),
                    'delivery_days' => $zone['delivery_days'],
                ];
            })->values()->all(),
        ];
    }
}
