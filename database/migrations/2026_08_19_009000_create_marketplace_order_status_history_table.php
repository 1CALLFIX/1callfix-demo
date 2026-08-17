<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 24 (Marketplace Foundation). Mirrors `property_reservation_status_history` exactly. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index('marketplace_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_status_history');
    }
};
