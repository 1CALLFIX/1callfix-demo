<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hotels H1 (owner 2026-11-12): a hotel joins through /partners and waits for approval, like a shop or restaurant.
 * approval_status pending → approved / rejected (existing rows were added by an admin, so they start approved).
 * needs_acceptance: null = follow Hotel Settings, true/false = this hotel's own rule. photos: extra pictures (paths).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('pending')->after('is_active');
            $table->string('rejection_reason', 500)->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('rejection_reason');
            $table->string('gst_number', 20)->nullable()->after('contact_email');
            $table->json('photos')->nullable()->after('cover_image');
            $table->json('facilities')->nullable()->after('photos');
            $table->boolean('needs_acceptance')->nullable()->after('facilities');
            $table->boolean('is_featured')->default(false)->after('needs_acceptance');
            $table->index('approval_status');
        });

        DB::table('accommodations')->update(['approval_status' => 'approved', 'approved_at' => now()]);

        Schema::table('hotel_room_types', function (Blueprint $table) {
            $table->json('photos')->nullable()->after('cover_image');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_room_types', function (Blueprint $table) {
            $table->dropColumn('photos');
        });
        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'rejection_reason', 'approved_at', 'gst_number', 'photos', 'facilities', 'needs_acceptance', 'is_featured']);
        });
    }
};
