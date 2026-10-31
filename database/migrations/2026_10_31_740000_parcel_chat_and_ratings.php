<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel Stage 2 — chat between the sender and the rider while the parcel is on its way, and the sender's rating of
 * the rider after delivery. Their own tables: chat_messages and reviews are tied to service bookings and providers
 * (both FKs NOT NULL), and a rider is a field worker, not a provider. New tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_order_id')->constrained('parcel_orders')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 500);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['parcel_order_id', 'id']);
        });

        Schema::create('parcel_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_order_id')->unique()->constrained('parcel_orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('field_worker_id')->constrained('field_workers')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->index('field_worker_id');
        });

        // Block one sender from sending parcels (admin button on the customer page). Null = allowed.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('parcel_blocked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('parcel_blocked_at');
        });
        Schema::dropIfExists('parcel_ratings');
        Schema::dropIfExists('parcel_order_messages');
    }
};
