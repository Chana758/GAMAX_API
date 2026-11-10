<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** KHQR orders: the exact amount to transfer, the deadline, and when the customer / staff confirmed it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Frozen at order time, so a later exchange-rate change doesn't change what was asked
            $table->decimal('payment_amount', 14, 2)->nullable();
            $table->string('payment_currency', 3)->nullable();          // KHR or USD
            $table->string('payment_reference', 100)->nullable();       // from the customer's bank app (optional)
            $table->timestamp('payment_due_at')->nullable();            // unpaid after this -> cancelled
            $table->timestamp('payment_submitted_at')->nullable();      // customer tapped "I've paid"
            $table->timestamp('paid_at')->nullable();                   // staff saw the money

            $table->index(['status', 'payment_due_at']);                // finding overdue unpaid orders
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment_due_at']);
            $table->dropColumn([
                'payment_amount', 'payment_currency', 'payment_reference',
                'payment_due_at', 'payment_submitted_at', 'paid_at',
            ]);
        });
    }
};
