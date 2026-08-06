<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extra work a provider finds mid-job (e.g. booked for a tap fitting at ₹300,
// discovers a kitchen sink leak, wants to charge ₹1000 more for it). Each item
// needs explicit customer approval before it's added to the final bill —
// nothing gets silently added to what the customer pays.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_extra_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending_approval', 'approved', 'rejected'])
                ->default('pending_approval');
            $table->foreignId('added_by_provider_id')->constrained('providers')->cascadeOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extra_items');
    }
};
