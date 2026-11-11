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
            $table->string('payment_currency', 3)->nullable();          
            $table->string('payment_reference', 100)->nullable();       
            $table->timestamp('payment_due_at')->nullable();            
            $table->timestamp('payment_submitted_at')->nullable();      
            $table->timestamp('paid_at')->nullable();                   

            $table->index(['status', 'payment_due_at']);             
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
