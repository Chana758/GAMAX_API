<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('series');                 // "RTX 40", "MacBook"...
            $table->string('name');                   // "NVIDIA GeForce RTX 4090"
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();      // /images/pic1.png
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};