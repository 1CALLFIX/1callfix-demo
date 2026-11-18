<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicles for rent (owner 2026-11-18, "OLX-style"): an owner lists their car, bike, auto, van, truck or tractor; the
 * team approves it; customers in the city browse and contact the owner. No booking or payment between customer and
 * owner: the platform earns the listing fee (listing_fees, listing_type = 'vehicle'). Kept apart from the admin
 * `vehicles` catalog that the reservation engine uses, so nothing there changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->string('slug', 180)->unique();
            $table->string('vehicle_kind', 60)->nullable();
            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('fuel', 40)->nullable();
            $table->string('transmission', 40)->nullable();
            $table->unsignedTinyInteger('seats')->nullable();
            $table->json('driver_options')->nullable();
            $table->decimal('price_day', 10, 2)->nullable();
            $table->decimal('price_hour', 10, 2)->nullable();
            $table->decimal('deposit', 10, 2)->nullable();
            $table->unsignedInteger('km_per_day')->nullable();
            $table->json('features')->nullable();
            $table->json('rule_list')->nullable();
            $table->text('about')->nullable();
            $table->string('landmark', 160)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('cover_image')->nullable();
            $table->json('photos')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('status', 20)->default('draft'); // draft | in_review | approved | rejected
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('featured_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('pending_changes')->nullable();
            $table->timestamp('pending_requested_at')->nullable();
            $table->string('pending_rejection', 500)->nullable();
            $table->timestamps();
            $table->index(['franchise_id', 'status', 'is_active']);
        });

        Schema::create('vehicle_contact_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['vehicle_listing_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_contact_views');
        Schema::dropIfExists('vehicle_listings');
    }
};
