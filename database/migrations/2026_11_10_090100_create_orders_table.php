<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();                         // GMX-261110-7KQ2, shown to customers
            // Orders are kept for the shop's records even if the customer account is deleted
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 30)->default('pending_confirmation');
            $table->string('payment_method', 20);                           // cod (khqr, aba later)
            $table->string('payment_status', 20)->default('unpaid');        // unpaid, paid, refunded

            // Copy of the delivery address at order time
            $table->string('ship_name', 100);
            $table->string('ship_phone', 20);
            $table->string('ship_email')->nullable();
            $table->string('ship_province', 50);
            $table->string('ship_address', 500);
            $table->string('note', 500)->nullable();                        // from the customer

            // Money (USD). Calculated by the server, never taken from the browser.
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('shipping_fee', 10, 2)->default(0);
            $table->decimal('cod_fee', 10, 2)->default(0);
            $table->decimal('total', 12, 2);

            $table->string('tracking_number', 100)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('cancelled_by', 20)->nullable();                 // customer, staff
            $table->string('admin_note', 1000)->nullable();                 // staff only

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);                       // "My orders"
            $table->index(['status', 'created_at']);                        // admin list filtered by status
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_check CHECK (subtotal >= 0 AND discount >= 0 AND shipping_fee >= 0 AND cod_fee >= 0 AND total >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
