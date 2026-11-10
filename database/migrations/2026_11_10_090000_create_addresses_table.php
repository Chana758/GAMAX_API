<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Delivery addresses saved by customers. Orders copy the address, so editing one never changes an old order. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete(); // PostgreSQL doesn't index FKs
            $table->string('label', 30)->default('Home');                  // Home, Office...
            $table->string('full_name', 100);
            $table->string('phone', 20);
            $table->string('email')->nullable();
            $table->string('province', 50);                                 // one of config('shop.provinces')
            $table->string('address', 500);                                 // house, street, sangkat, khan
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
