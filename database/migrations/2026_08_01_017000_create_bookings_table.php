<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P1
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('address_id')->constrained()->cascadeOnDelete();
            $table->enum('status', [
                'pending','searching_provider','assigned','provider_en_route',
                'in_progress','completed','cancelled','disputed'
            ])->default('pending');
            $table->timestamp('scheduled_at')->nullable(); // null = instant booking
            $table->decimal('price_quoted', 10, 2);
            $table->decimal('price_final', 10, 2)->nullable();
            $table->enum('payment_status', ['pending','paid','refunded','partially_refunded'])->default('pending');
            $table->enum('payment_method', ['online','cash','wallet'])->default('online');
            $table->foreignId('coupon_id')->nullable();
            $table->foreignId('cancellation_reason_id')->nullable()->constrained('cancellation_reasons')->nullOnDelete();
            $table->text('cancellation_note')->nullable();
            $table->text('customer_note')->nullable();
            $table->string('start_otp', 6)->nullable();
            $table->string('completion_otp', 6)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
