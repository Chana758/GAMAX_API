<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    // Prepaid orders waiting for payment (KHQR / ABA, later)
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

    /** Customers can cancel by themselves until the order ships. */
    public const CUSTOMER_CANCELLABLE = [
        self::PENDING, self::PENDING_CONFIRMATION, self::CONFIRMED, self::PROCESSING,
    ];

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_STATUSES = [self::PAYMENT_UNPAID, self::PAYMENT_PAID, self::PAYMENT_REFUNDED];

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

    public function canBeCancelledByCustomer(): bool
    {
        return in_array($this->status, self::CUSTOMER_CANCELLABLE, true);
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
