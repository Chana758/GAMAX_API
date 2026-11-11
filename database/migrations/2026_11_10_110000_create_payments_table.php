<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every amount of money that came in (or went back) for an order is one row.
 * One order can have several: deposit + balance, cash at the counter, two people
 * paying the same QR (overpaid), a refund...
 * Also: where the order was sold (channel) and how it reaches the customer (fulfillment).
 */
return new class extends Migration
{
    private const REFERENCE_INDEX = 'payments_provider_reference_unique';

    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->index()->constrained()->restrictOnDelete();
            $table->string('type', 10)->default('payment');         
            $table->string('method', 20);                             
            $table->string('provider', 30);
            $table->string('reference', 100)->nullable();          
            $table->decimal('amount', 12, 2);                        
            $table->string('currency', 3)->default('USD');           
            $table->decimal('amount_received', 14, 2);               
            $table->decimal('exchange_rate', 12, 4)->nullable(); 
            $table->string('source', 10);                         
            $table->string('payer_account', 150)->nullable();        
            $table->string('payer_name', 150)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            // Case-insensitive, and rows without a reference (cash without a receipt) are not checked
            DB::statement('CREATE UNIQUE INDEX ' . self::REFERENCE_INDEX
                . ' ON payments (provider, LOWER(reference)) WHERE reference IS NOT NULL');
        } else {
            // MySQL: no partial index; NULL references never clash, and the default collation ignores case
            Schema::table('payments', fn (Blueprint $table) => $table->unique(['provider', 'reference'], self::REFERENCE_INDEX));
        }

        if (in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0 AND amount_received > 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_rate_check CHECK (exchange_rate IS NULL OR exchange_rate > 0)');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 20)->default('web');          
            $table->string('fulfillment', 20)->default('delivery');   
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); 
            $table->decimal('amount_paid', 12, 2)->default(0);       
            $table->decimal('payment_exchange_rate', 12, 4)->nullable();
            $table->index(['channel', 'created_at']);
            $table->index(['payment_status', 'created_at']);
        });

        // Orders already marked paid before this table existed get one payment row (in USD:
        // the rate used for older riel orders was not stored, so it is not guessed here)
        $providers = ['khqr' => 'bakong', 'bank_transfer' => 'bank', 'card' => 'card', 'cash' => 'shop', 'cod' => 'courier'];

        DB::table('orders')->where('payment_status', 'paid')->orderBy('id')->each(function ($order) use ($providers) {
            DB::table('payments')->insert([
                'order_id'        => $order->id,
                'type'            => 'payment',
                'method'          => $order->payment_method,
                'provider'        => $providers[$order->payment_method] ?? $order->payment_method,
                'amount'          => $order->total,
                'currency'        => 'USD',
                'amount_received' => $order->total,
                'source'          => 'system',
                'note'            => 'Recorded before the payments table existed',
                'created_at'      => $order->paid_at ?? $order->updated_at,
                'updated_at'      => now(),
            ]);
            DB::table('orders')->where('id', $order->id)->update(['amount_paid' => $order->total]);
        });
    }

    public function down(): void
    {
        // Written to also undo an earlier version of this migration (columns checked one by one)
        Schema::table('orders', function (Blueprint $table) {
            foreach (['orders_channel_created_at_index', 'orders_payment_status_created_at_index'] as $index) {
                if (Schema::hasIndex('orders', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        if (Schema::hasColumn('orders', 'created_by')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('created_by'));
        }

        $columns = array_values(array_filter(
            ['channel', 'fulfillment', 'amount_paid', 'payment_exchange_rate'],
            fn ($column) => Schema::hasColumn('orders', $column)
        ));
        if ($columns) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($columns));
        }

        Schema::dropIfExists('payments');
    }
};
