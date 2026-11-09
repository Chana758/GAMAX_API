<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A variant that was already ordered cannot be deleted (order history needs it).
 * is_active = false hides it from the store instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('stock_quantity');
            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'is_active']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
