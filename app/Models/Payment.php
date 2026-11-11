<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money in (type payment) or money back (type refund) for one order.
 *
 * payer_account / payer_name only say where the money came from, as shown by the bank
 * or told by the customer. They are NOT proof of who owns the order (anyone may pay
 * for someone else's order): the owner is always orders.user_id.
 */
class Payment extends Model
{
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_REFUND = 'refund';

    // How the money moved
    public const METHODS = ['khqr', 'cash', 'card', 'bank_transfer', 'cod'];

    // Who issued the reference when staff don't say: one transaction per provider + reference
    public const DEFAULT_PROVIDERS = [
        'khqr'          => 'bakong',   // Bakong transaction hash
        'bank_transfer' => 'bank',     // better: the bank code (aba, aclb, wing...)
        'card'          => 'card',     // POS approval code
        'cash'          => 'shop',     // our receipt number
        'cod'           => 'courier',  // courier's slip number
    ];

    // Who told the system about it
    public const SOURCE_STAFF = 'staff';    // recorded by a person in admin
    public const SOURCE_BAKONG = 'bakong';  // confirmed by the Bakong API (later)
    public const SOURCE_SYSTEM = 'system';  // created by the app itself

    protected $fillable = [
        'type',
        'method',
        'provider',
        'reference',
        'amount',
        'currency',
        'amount_received',
        'exchange_rate',
        'source',
        'payer_account',
        'payer_name',
        'note',
        'recorded_by',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'amount_received' => 'decimal:2',
        'exchange_rate'   => 'decimal:4',
    ];

    public static function providerFor(string $method): string
    {
        return self::DEFAULT_PROVIDERS[$method] ?? $method;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
