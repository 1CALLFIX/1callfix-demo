<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P1
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete(); // null = global default
            $table->unsignedInteger('free_cancellation_minutes')->default(15);
            $table->enum('fee_type', ['flat','percent'])->default('flat');
            $table->decimal('fee_value', 10, 2)->default(0);
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_policies');
    }
};
