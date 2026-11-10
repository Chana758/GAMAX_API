<?php

/*
|--------------------------------------------------------------------------
| Shop rules for checkout and orders
|--------------------------------------------------------------------------
| The server always calculates order totals from these values. The storefront
| only displays them (GET /api/v1/settings), so a customer cannot change a price
| or a fee from the browser.
|
| !! The shipping and COD fees below are SAMPLE values copied from the old
| !! storefront. Replace them with the shop's real rates before going live.
| After editing on a server that caches config, run: php artisan config:clear
*/

return [

    'currency' => 'USD',

    // Orders whose item total reaches this amount ship free. 0 = never free.
    'free_shipping_over' => 50,

    // Delivery zones: delivery fee per order, extra fee for Cash on Delivery,
    // and the estimated delivery time shown to the customer (business days).
    'zones' => [
        'capital' => ['shipping_fee' => 2, 'cod_fee' => 0, 'delivery_days' => '1-3'],
        'near'    => ['shipping_fee' => 3, 'cod_fee' => 1, 'delivery_days' => '3-5'],
        'mid'     => ['shipping_fee' => 5, 'cod_fee' => 2, 'delivery_days' => '3-5'],
        'far'     => ['shipping_fee' => 7, 'cod_fee' => 3, 'delivery_days' => '3-5'],
    ],

    // Province => zone. These names are what customers choose and what is saved on orders.
    'provinces' => [
        'Phnom Penh'       => 'capital',
        'Kandal'           => 'near',
        'Kampong Speu'     => 'near',
        'Takeo'            => 'near',
        'Prey Veng'        => 'near',
        'Svay Rieng'       => 'near',
        'Kampong Cham'     => 'mid',
        'Tboung Khmum'     => 'mid',
        'Kampong Chhnang'  => 'mid',
        'Kampong Thom'     => 'mid',
        'Kampot'           => 'mid',
        'Kep'              => 'mid',
        'Preah Sihanouk'   => 'mid',
        'Siem Reap'        => 'mid',
        'Battambang'       => 'mid',
        'Pursat'           => 'mid',
        'Koh Kong'         => 'far',
        'Pailin'           => 'far',
        'Banteay Meanchey' => 'far',
        'Oddar Meanchey'   => 'far',
        'Preah Vihear'     => 'far',
        'Stung Treng'      => 'far',
        'Kratie'           => 'far',
        'Mondulkiri'       => 'far',
        'Ratanakiri'       => 'far',
    ],

    // Provinces where Cash on Delivery is not offered, e.g. ['Mondulkiri']
    'cod_excluded_provinces' => [],

    // Payment methods shown at checkout, in this order.
    // ('aba' card payments need an ABA PayWay merchant account first.)
    'payment_methods' => ['khqr', 'cod'],

    // true = orders paid by KHQR ship free (an incentive to pay online). Business decision.
    'free_shipping_for_prepaid' => false,

    /*
    | KHQR: after ordering, the customer scans the shop's QR (storefront file
    | src/assets/Images/QR.jpg), pays, and taps "I've paid". Staff check the bank
    | app and mark the order paid, which confirms it.
    | The current QR is SAM CHANNA's static ACLEDA QR in riel: it holds no amount,
    | so the customer types the riel amount shown on the payment screen.
    */
    'khqr' => [
        'account_name'     => 'SAM CHANNA',  // shown under the QR so customers know they pay the right shop
        'bank'             => 'ACLEDA',
        'currency'         => 'KHR',         // currency of the QR: KHR (riel) or USD
        'khr_per_usd'      => 4100,          // !! SAMPLE exchange rate: set the shop's own rate
        'pay_within_hours' => 24,            // unpaid KHQR orders are cancelled after this (stock goes back)
    ],

    // Highest order total accepted as Cash on Delivery (null = no limit)
    'cod_max_total' => null,

    // Most units of one product option in one order
    'max_quantity_per_item' => 10,

    // Saved delivery addresses per customer
    'max_addresses' => 10,
];
