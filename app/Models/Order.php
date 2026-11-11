<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    // KHQR orders waiting for the customer's payment
    public const PENDING = 'pending';
    // COD orders: the shop calls the customer before shipping
    public const PENDING_CONFIRMATION = 'pending_confirmation';
    public const CONFIRMED = 'confirmed';
    public const PROCESSING = 'processing';
    public const SHIPPED = 'shipped';
    public const DELIVERED = 'delivered';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PENDING, self::PENDING_CONFIRMATION, self::CONFIRMED,
        self::PROCESSING, self::SHIPPED, self::DELIVERED, self::CANCELLED,
    ];

    /** Which status staff may move an order to next. */
    public const NEXT_STATUSES = [
        self::PENDING              => [self::CONFIRMED, self::CANCELLED],
        self::PENDING_CONFIRMATION => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED            => [self::PROCESSING, self::SHIPPED, self::CANCELLED],
        self::PROCESSING           => [self::SHIPPED, self::CANCELLED],
        self::SHIPPED              => [self::DELIVERED],
        self::DELIVERED            => [],
        self::CANCELLED            => [],
    ];

    /** Customers can cancel by themselves until the order ships (and before they have paid). */
    public const CUSTOMER_CANCELLABLE = [
        self::PENDING, self::PENDING_CONFIRMATION, self::CONFIRMED, self::PROCESSING,
    ];

    // Money state, worked out from the payment rows (OrderPaymentService::refreshTotals)
    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_SUBMITTED = 'submitted';   // customer says "I've paid", staff still have to check
    public const PAYMENT_PARTIAL = 'partially_paid';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_OVERPAID = 'overpaid';     // more money came in than the total: refund the difference
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID, self::PAYMENT_SUBMITTED, self::PAYMENT_PARTIAL,
        self::PAYMENT_PAID, self::PAYMENT_OVERPAID, self::PAYMENT_REFUNDED,
    ];

    // Where the order was made
    public const CHANNELS = ['web', 'store', 'telegram', 'facebook', 'phone'];

    // How the customer gets it
    public const FULFILLMENTS = ['delivery', 'pickup'];

    protected $fillable = [
        'number',
        'user_id',
        'status',
        'payment_method',
        'payment_status',
        'ship_name',
        'ship_phone',
        'ship_email',
        'ship_province',
        'ship_address',
        'note',
        'subtotal',
        'discount',
        'shipping_fee',
        'cod_fee',
        'total',
        'payment_amount',
        'payment_currency',
        'payment_exchange_rate',
        'payment_reference',
        'payment_due_at',
        'payment_submitted_at',
        'paid_at',
        'channel',
        'fulfillment',
        'created_by',
        'amount_paid',
        'tracking_number',
        'cancel_reason',
        'cancelled_by',
        'admin_note',
        'confirmed_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected $casts = [
        'subtotal'     => 'decimal:2',
        'discount'     => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'cod_fee'      => 'decimal:2',
        'total'        => 'decimal:2',
        'payment_amount'       => 'decimal:2',
        'payment_exchange_rate' => 'decimal:4',
        'payment_due_at'       => 'datetime',
        'payment_submitted_at' => 'datetime',
        'paid_at'              => 'datetime',
        'amount_paid'          => 'decimal:2',
        'confirmed_at' => 'datetime',
        'shipped_at'   => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /** Staff member who created the order (counter / Telegram / phone sales). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Staff lists: COD orders waiting for the confirmation call. */
    public function scopeToCall(Builder $query): Builder
    {
        return $query->where('status', self::PENDING_CONFIRMATION);
    }

    /** KHQR orders where the customer says "I've paid": check the bank app. */
    public function scopePaymentToCheck(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)->where('payment_status', self::PAYMENT_SUBMITTED);
    }

    /** Money to give back: overpaid, or cancelled while money is still held. */
    public function scopeNeedsRefund(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('payment_status', self::PAYMENT_OVERPAID)
            ->orWhere(fn ($q) => $q->where('status', self::CANCELLED)->where('amount_paid', '>', 0)));
    }

    /** Confirmed, waiting to be packed / shipped. */
    public function scopeToShip(Builder $query): Builder
    {
        return $query->whereIn('status', [self::CONFIRMED, self::PROCESSING]);
    }

    /** Still to collect, in USD (negative = overpaid). */
    public function balanceDue(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    /** Once any money was sent, the shop handles the cancellation (and the refund). */
    public function canBeCancelledByCustomer(): bool
    {
        return in_array($this->status, self::CUSTOMER_CANCELLABLE, true)
            && $this->payment_status === self::PAYMENT_UNPAID;
    }

    /** KHQR order that still waits for the customer's payment. */
    public function awaitsPayment(): bool
    {
        return $this->payment_method !== 'cod'
            && $this->status === self::PENDING
            && $this->payment_status === self::PAYMENT_UNPAID
            && ($this->payment_due_at === null || $this->payment_due_at->isFuture());
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::NEXT_STATUSES[$this->status] ?? [], true);
    }

    /**
     * GMX-261110-7KQ2: date + 4 random characters without look-alikes (no 0/O, 1/I).
     * Easy to read out on the phone. The unique index is the final guard.
     */
    public static function newNumber(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $suffix = '';
            for ($i = 0; $i < 4; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $number = 'GMX-' . now()->format('ymd') . '-' . $suffix;
        } while (static::where('number', $number)->exists());

        return $number;
    }
}
