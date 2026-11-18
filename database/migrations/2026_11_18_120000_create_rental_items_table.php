<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rent anything (owner decision 2026-11-18, OLX-style): any signed-in person with a verified mobile number posts an item
 * they rent out (camera, speaker, furniture, tools, party items...). The franchise team approves each post before
 * customers see it; customers contact the owner by call / WhatsApp. No booking or payment runs between them.
 * Customers can report an item; enough open reports hide it until the team checks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('title', 160);
            $table->string('slug', 180)->unique();
            $table->string('category', 60)->nullable();
            $table->string('condition', 40)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('price_unit', 30)->nullable();
            $table->decimal('deposit', 10, 2)->nullable();
            $table->boolean('can_deliver')->default(false);
            $table->text('about')->nullable();
            $table->string('landmark', 160)->nullable();
            $table->string('cover_image')->nullable();
            $table->json('photos')->nullable();
            $table->string('status', 20)->default('draft'); // draft | in_review | approved | rejected
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('featured_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('open_reports')->default(0);
            $table->timestamp('hidden_for_reports_at')->nullable();
            $table->timestamps();
            $table->index(['franchise_id', 'status', 'is_active']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('rental_item_contact_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['rental_item_id', 'customer_id']);
        });

        Schema::create('rental_item_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 60);
            $table->string('note', 500)->nullable();
            $table->string('status', 12)->default('open'); // open | dismissed | upheld
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->unique(['rental_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_item_reports');
        Schema::dropIfExists('rental_item_contact_views');
        Schema::dropIfExists('rental_items');
    }
};
