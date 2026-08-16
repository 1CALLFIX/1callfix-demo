<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 22.4 (Parcel). A direct, deliberate mirror of `booking_status_history`
// -- same shape, same reasoning (a proven, tested audit-trail table, no
// invention needed) -- rather than touching `booking_status_history` itself
// or forcing a polymorphic column onto a heavily-referenced existing table
// for a single-purpose, cheap-to-duplicate concern.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_order_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_order_status_history');
    }
};
