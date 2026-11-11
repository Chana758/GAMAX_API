<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\ShopRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that adds money to an order or gives it back.
 * amount_paid and payment_status are always worked out from the payment rows.
 */
class OrderPaymentService
{
    /**
     * Money received: cash at the counter, KHQR seen in the bank app, COD collected...
     * Overpaying is allowed (it happens: two people pay the same QR) and shows as "overpaid".
     */
    public function record(Order $order, array $data, ?User $staff, string $source = Payment::SOURCE_STAFF): Order
    {
        return DB::transaction(function () use ($order, $data, $staff, $source) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->addRow($order, Payment::TYPE_PAYMENT, $data, $staff, $source);
            $this->refreshTotals($order);

            // A KHQR order waiting for its money is confirmed once fully paid
            if ($order->status === Order::PENDING && $order->balanceDue() <= 0) {
                $order->update(['status' => Order::CONFIRMED, 'confirmed_at' => $order->confirmed_at ?? now()]);
            }

            return $order;
        });
    }

    /** Money given back to the customer (overpaid, cancelled after paying, return...). */
    public function refund(Order $order, array $data, User $staff): Order
    {
        return DB::transaction(function () use ($order, $data, $staff) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->addRow($order, Payment::TYPE_REFUND, $data, $staff, Payment::SOURCE_STAFF);
            $this->refreshTotals($order);

            return $order;
        });
    }

    /**
     * Same as record(), for code that already holds the order lock
     * (OrderService: COD collected on delivery, KHQR confirmed by staff).
     */
    public function addWithinLock(Order $order, array $data, ?User $staff, string $source = Payment::SOURCE_STAFF): void
    {
        $this->addRow($order, Payment::TYPE_PAYMENT, $data, $staff, $source);
        $this->refreshTotals($order);
    }

    /** Recalculate amount_paid and payment_status from the rows. Call while holding the order lock. */
    public function refreshTotals(Order $order): void
    {
        // reorder(): PostgreSQL refuses SUM() together with the relation's ORDER BY id
        $paid = ShopRules::cents($order->payments()->reorder()->where('type', Payment::TYPE_PAYMENT)->sum('amount'));
        $refunded = ShopRules::cents($order->payments()->reorder()->where('type', Payment::TYPE_REFUND)->sum('amount'));
        $net = $paid - $refunded;
        $total = ShopRules::cents($order->total);

        $status = match (true) {
            $refunded > 0 && $net <= 0 => Order::PAYMENT_REFUNDED,
            // Nothing confirmed yet: keep "submitted" if the customer said they paid
            $net <= 0 => $order->payment_status === Order::PAYMENT_SUBMITTED ? Order::PAYMENT_SUBMITTED : Order::PAYMENT_UNPAID,
            $net < $total => Order::PAYMENT_PARTIAL,
            $net === $total => Order::PAYMENT_PAID,
            default => Order::PAYMENT_OVERPAID,
        };

        $order->update([
            'amount_paid'    => ShopRules::dollars($net),
            'payment_status' => $status,
            // First time fully paid (kept afterwards, for reports)
            'paid_at'        => $order->paid_at ?? ($net >= $total && $total > 0 ? now() : null),
        ]);
    }

    private function addRow(Order $order, string $type, array $data, ?User $staff, string $source): Payment
    {
        [$cents, $currency, $received, $rate] = $this->money($order, $type, $data);

        if ($type === Payment::TYPE_REFUND && $cents > ShopRules::cents($order->amount_paid)) {
            throw ValidationException::withMessages([
                'amount' => 'You can refund at most $' . number_format((float) $order->amount_paid, 2) . ', the amount paid so far.',
            ]);
        }

        $provider = strtolower(trim($data['provider'] ?? '')) ?: Payment::providerFor($data['method']);
        $reference = trim((string) ($data['reference'] ?? '')) ?: null;
        $this->ensureNewReference($provider, $reference);

        try {
            return $order->payments()->create([
                'type'            => $type,
                'method'          => $data['method'],
                'provider'        => $provider,
                'reference'       => $reference,
                'amount'          => ShopRules::dollars($cents),
                'currency'        => $currency,
                'amount_received' => $received,
                'exchange_rate'   => $rate,
                'source'          => $source,
                'payer_account'   => $data['payer_account'] ?? null,
                'payer_name'      => $data['payer_name'] ?? null,
                'note'            => $data['note'] ?? null,
                'recorded_by'     => $staff?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two people recorded the same transaction at the same moment
            throw ValidationException::withMessages([
                'reference' => "Transaction {$reference} ({$provider}) is already recorded.",
            ]);
        }
    }

    /** One transaction of one provider can only be counted once (also enforced by a unique index). */
    private function ensureNewReference(string $provider, ?string $reference): void
    {
        if ($reference === null) {
            return;
        }

        $existing = Payment::with('order:id,number')
            ->where('provider', $provider)
            ->whereRaw('LOWER(reference) = ?', [mb_strtolower($reference)])
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'reference' => "Transaction {$reference} ({$provider}) is already recorded on order {$existing->order->number}.",
            ]);
        }
    }

    /**
     * [USD cents counted for the order, currency received, amount received, exchange rate].
     * Riel is converted with the rate given (or the shop's rate), and the rate is stored
     * with the payment so the conversion can always be checked later.
     */
    private function money(Order $order, string $type, array $data): array
    {
        $currency = strtoupper($data['currency'] ?? 'USD');

        if ($currency !== 'KHR') {
            $cents = ShopRules::cents($data['amount']);

            return [$cents, 'USD', ShopRules::dollars($cents), null];
        }

        $riel = (float) $data['amount_received'];
        $rate = (float) ($data['exchange_rate'] ?? config('shop.khqr.khr_per_usd', 4100));
        $cents = (int) round($riel / $rate * 100);

        // Riel amounts are rounded to 100 riel, so paying (or refunding) exactly the amount due
        // in riel can come out a few cents off. Within one 100-riel step, count it as exact.
        $target = ShopRules::cents($type === Payment::TYPE_REFUND ? $order->amount_paid : $order->balanceDue());
        $step = (int) ceil(100 / $rate * 100);
        if ($target > 0 && abs($cents - $target) <= $step) {
            $cents = $target;
        }

        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount_received' => 'The amount in riel is too small.']);
        }

        return [$cents, 'KHR', round($riel, 2), $rate];
    }
}
