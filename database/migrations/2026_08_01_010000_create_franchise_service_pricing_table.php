<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P1
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise_service_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->decimal('price_override', 10, 2)->nullable();
            $table->boolean('is_offered')->default(true);
            $table->timestamps();
            $table->unique(['franchise_id','service_id']);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_service_pricing');
    }
};
