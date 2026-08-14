<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which Services one flash sale applies to — a plain pivot, not polymorphic:
// Service is the only catalog entity that currently has its own pricing
// (base_price/discount_price + FranchiseServicePricing's override), so a
// flash sale price computation is inherently Service-specific. Unlike
// Badge (genuinely entity-agnostic — a label carries no pricing math),
// inventing a polymorphic target here would mean pretending price
// computation works identically for entities that don't have a price to
// begin with.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sale_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['flash_sale_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_targets');
    }
};
