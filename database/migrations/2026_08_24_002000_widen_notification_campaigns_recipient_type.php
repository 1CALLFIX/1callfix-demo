<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified "All Users" Directory session, Part 2 (Bulk Notify) — the
 * existing 'specific_user' recipient_type only ever supported ONE target
 * (specific_user_id, a single FK). Bulk Notify's checkbox multi-select
 * needs an arbitrary LIST of admin-picked individuals, which is a
 * materially different shape (no single FK to point at) — 'selected_users'
 * is a new recipient_type carrying its id list in the existing `filters`
 * JSON column (already exists for exactly this kind of resolver-specific
 * parameter — no new column needed), read by AudienceResolver::resolve().
 * Same additive enum-widen technique already used for users.role
 * (2026_08_11_015000) — Laravel's native change() rather than a raw
 * MySQL-only ALTER, so this runs on both MySQL (production) and SQLite
 * (the test suite).
 */
return new class extends Migration
{
    private const OLD = ['customers', 'providers', 'staff', 'everyone', 'specific_user'];
    private const NEW = ['customers', 'providers', 'staff', 'everyone', 'specific_user', 'selected_users'];

    public function up(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->enum('recipient_type', self::NEW)->default('everyone')->change();
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->enum('recipient_type', self::OLD)->default('everyone')->change();
        });
    }
};
