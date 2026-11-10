<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ShopRules;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checkout and order life cycle. All money is calculated here from the database,
 * never taken from the browser.
 */
class OrderService
{
    /** Totals for the checkout review step. Saves nothing. */
    public function quote(User $user, array $data): array
    {
        $address = $this->customerAddress($user, $data['address_id']);
        $lines = $this->mergeLines($data['items']);
        $variants = $this->loadVariants(array_keys($lines), lock: false);

        return $this->price($lines, $variants, $address, $data['payment_method']);
    }

    /**
     * Create the order. Stock is taken in the same transaction, with the variant rows
     * locked, so two customers can never buy the last unit twice.
     */
    public function place(User $user, array $data): Order
    {
        $address = $this->customerAddress($user, $data['address_id']);
        $lines = $this->mergeLines($data['items']);

        return DB::transaction(function () use ($user, $data, $address, $lines) {
            $variants = $this->loadVariants(array_keys($lines), lock: true);
            $quote = $this->price($lines, $variants, $address, $data['payment_method']);

            // The customer saw a different total (price or fee changed meanwhile): show the new one first
            if (isset($data['expected_total']) && ShopRules::cents($data['expected_total']) !== ShopRules::cents($quote['total'])) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'code'    => 'price_changed',
                    'message' => 'Some prices or fees changed since you opened checkout. Please check the new total.',
                    'data'    => $quote,
                ], Response::HTTP_CONFLICT));
            }

            foreach ($lines as $variantId => $quantity) {
                $taken = ProductVariant::whereKey($variantId)
                    ->where('stock_quantity', '>=', $quantity)
                    ->decrement('stock_quantity', $quantity);

                if ($taken === 0) { // only possible without row locks (SQLite)
                    throw ValidationException::withMessages(['items' => 'An item just sold out. Please review your cart.']);
                }
            }
            ProductVariant::whereKey(array_keys($lines))->where('stock_quantity', '<=', 0)->update(['in_stock' => false]);

            $order = Order::create([
                'number'         => Order::newNumber(),
                'user_id'        => $user->id,
                'status'         => $data['payment_method'] === 'cod' ? Order::PENDING_CONFIRMATION : Order::PENDING,
                'payment_method' => $data['payment_method'],
                'payment_status' => Order::PAYMENT_UNPAID,
                'ship_name'      => $address->full_name,
                'ship_phone'     => $address->phone,
                'ship_email'     => $address->email ?? $user->email,
                'ship_province'  => $address->province,
                'ship_address'   => $address->address,
                'note'           => $data['note'] ?? null,
                'subtotal'       => $quote['subtotal'],
                'discount'       => $quote['discount'],
                'shipping_fee'   => $quote['shipping_fee'],
                'cod_fee'        => $quote['cod_fee'],
                'total'          => $quote['total'],
            ]);

            foreach ($quote['items'] as $line) {
                $variant = $variants[$line['variant_id']];
                $order->items()->create([
                    'product_id'         => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_name'       => $variant->product->name,
                    'variant_title'      => $variant->title,
                    'sku'                => $variant->sku,
                    'config'             => $variant->config,
                    'image'              => $variant->product->image,
                    'unit_price'         => $line['unit_price'],
                    'quantity'           => $line['quantity'],
                    'line_total'         => $line['line_total'],
                ]);
            }

            return $order->load('items.product:id,slug');
        });
    }

    public function cancelByCustomer(Order $order, ?string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->canBeCancelledByCustomer()) {
                throw ValidationException::withMessages([
                    'status' => 'This order has already shipped or was cancelled. Please contact us for help.',
                ]);
            }

            $this->restock($order);
            $order->update([
                'status'        => Order::CANCELLED,
                'cancelled_at'  => now(),
                'cancelled_by'  => 'customer',
                'cancel_reason' => $reason,
            ]);

            return $order->load('items.product:id,slug');
        });
    }

    /** Staff: change status, tracking number, payment status or the internal note. */
    public function updateByStaff(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $changes = array_intersect_key($data, array_flip(['tracking_number', 'payment_status', 'admin_note']));

            $to = $data['status'] ?? $order->status;
            if ($to !== $order->status) {
                if (! $order->canMoveTo($to)) {
                    throw ValidationException::withMessages([
                        'status' => "A {$order->status} order can't be changed to {$to}.",
                    ]);
                }

                $changes['status'] = $to;
                $now = now();

                if ($to === Order::CONFIRMED) {
                    $changes['confirmed_at'] = $now;
                }
                if ($to === Order::SHIPPED) {
                    $changes['confirmed_at'] = $order->confirmed_at ?? $now;
                    $changes['shipped_at'] = $now;
                }
                if ($to === Order::DELIVERED) {
                    $changes['delivered_at'] = $now;
                    // COD: the courier collected the money
                    if ($order->payment_method === 'cod' && ! array_key_exists('payment_status', $data)) {
                        $changes['payment_status'] = Order::PAYMENT_PAID;
                    }
                }
                if ($to === Order::CANCELLED) {
                    $this->restock($order);
                    $changes['cancelled_at'] = $now;
                    $changes['cancelled_by'] = 'staff';
                    $changes['cancel_reason'] = $data['cancel_reason'] ?? null;
                }
            }

            $order->update($changes);

            return $order->load(['items.product:id,slug', 'user:id,name,email,phone']);
        });
    }

    /** Put the units of a cancelled order back on the shelf. */
    private function restock(Order $order): void
    {
        foreach ($order->items()->get() as $item) {
            ProductVariant::whereKey($item->product_variant_id)
                ->increment('stock_quantity', $item->quantity, ['in_stock' => true]);
        }
    }

    private function customerAddress(User $user, $addressId): Address
    {
        $address = $user->addresses()->find($addressId);

        if (! $address) {
            throw ValidationException::withMessages(['address_id' => 'Please choose one of your saved addresses.']);
        }
        if (! ShopRules::hasProvince($address->province)) {
            throw ValidationException::withMessages([
                'address_id' => 'We no longer deliver to the province in this address. Please edit it or choose another one.',
            ]);
        }

        return $address;
    }

    /** [variant_id => quantity], the same option sent twice is added up. */
    private function mergeLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $id = (int) $item['variant_id'];
            $lines[$id] = ($lines[$id] ?? 0) + (int) $item['quantity'];
        }

        $max = ShopRules::maxQuantityPerItem();
        foreach ($lines as $quantity) {
            if ($quantity > $max) {
                throw ValidationException::withMessages(['items' => "You can order at most {$max} of each item."]);
            }
        }

        return $lines;
    }

    /** Variants keyed by id. Locked rows are taken in id order so two checkouts can't deadlock. */
    private function loadVariants(array $ids, bool $lock): Collection
    {
        $query = ProductVariant::with('product:id,name,image,is_active')->whereKey($ids)->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
    }

    private function price(array $lines, Collection $variants, Address $address, string $paymentMethod): array
    {
        $errors = [];
        $items = [];
        $itemsCents = 0;

        foreach ($lines as $variantId => $quantity) {
            $variant = $variants->get($variantId);

            if (! $variant || ! $variant->is_active || ! $variant->product?->is_active) {
                $name = $variant?->title ?? "Item #{$variantId}";
                $errors["items.{$variantId}"] = "\"{$name}\" is no longer available. Please remove it from your cart.";
                continue;
            }
            if ($variant->stock_quantity < $quantity) {
                $errors["items.{$variantId}"] = $variant->stock_quantity === 0
                    ? "\"{$variant->title}\" is sold out. Please remove it from your cart."
                    : "Only {$variant->stock_quantity} left of \"{$variant->title}\". Please lower the quantity.";
                continue;
            }

            $unitCents = ShopRules::cents($variant->price);
            $itemsCents += $unitCents * $quantity;
            $items[] = [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'title'      => $variant->title,
                'unit_price' => ShopRules::dollars($unitCents),
                'quantity'   => $quantity,
                'line_total' => ShopRules::dollars($unitCents * $quantity),
                'stock_left' => $variant->stock_quantity,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $province = $address->province;
        if ($paymentMethod === 'cod' && ! ShopRules::codAllowed($province)) {
            throw ValidationException::withMessages(['payment_method' => "Cash on Delivery is not available in {$province}."]);
        }

        $discountCents = 0; // coupons come later
        $shippingCents = ShopRules::shippingFeeCents($itemsCents - $discountCents, $province);
        $codCents = ShopRules::codFeeCents($paymentMethod, $province);
        $totalCents = $itemsCents - $discountCents + $shippingCents + $codCents;

        $codMax = ShopRules::codMaxTotalCents();
        if ($paymentMethod === 'cod' && $codMax !== null && $totalCents > $codMax) {
            throw ValidationException::withMessages([
                'payment_method' => 'Cash on Delivery is available for orders up to $' . number_format($codMax / 100, 2) . '.',
            ]);
        }

        return [
            'items'          => $items,
            'payment_method' => $paymentMethod,
            'province'       => $province,
            'delivery_days'  => ShopRules::zone($province)['delivery_days'],
            'subtotal'       => ShopRules::dollars($itemsCents),
            'discount'       => ShopRules::dollars($discountCents),
            'shipping_fee'   => ShopRules::dollars($shippingCents),
            'cod_fee'        => ShopRules::dollars($codCents),
            'total'          => ShopRules::dollars($totalCents),
        ];
    }
}
