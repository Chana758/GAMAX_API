<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per product option in an order. Name, title, SKU and price are copied,
 * so later catalog edits never change what the customer bought.
 * A variant (or product) that was ordered cannot be deleted: hide it instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            // Indexed: PostgreSQL doesn't index FKs, and deleting a variant must check this table quickly
            $table->foreignId('order_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->index()->constrained()->restrictOnDelete();

            $table->string('product_name');
            $table->string('variant_title');
            $table->string('sku')->nullable();
            $table->json('config')->nullable();
            $table->string('image')->nullable();

            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
