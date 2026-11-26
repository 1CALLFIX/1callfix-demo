<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-26: a "Verified 1CallFix Partner" certificate for every business partner (shops, restaurants, hotels,
 * function halls, event organisers), to print and show at the counter, with a "Scan to order / book" sticker. One
 * certificate per business. Its QR opens /verify/{token}, the same live check as the ID card: an old, copied or
 * switched-off business's certificate fails the scan. A new token (yearly renewal) makes the old print invalid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('business_type', 20); // store | hotel | hall | organiser
            $table->unsignedBigInteger('business_id');
            $table->string('token', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('valid_until');
            $table->timestamps();
            $table->unique(['business_type', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_certificates');
    }
};
