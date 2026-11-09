<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL does not index foreign-key columns by itself (MySQL does),
 * so every "products of this category/brand" lookup was a full table scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('category_id');
            $table->index('brand_id');
            // Public list: WHERE is_active = true ORDER BY id DESC
            $table->index(['is_active', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['category_id']);
            $table->dropIndex(['brand_id']);
            $table->dropIndex(['is_active', 'id']);
        });
    }
};
