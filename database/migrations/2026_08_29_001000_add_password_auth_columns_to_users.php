<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auth rebuild — password-first login, Firebase OTP as verification only.
 *
 * The original users table (2026_08_01_003000) already carries a nullable
 * `password` column that was never populated (OTP-only login until now) and
 * `phone_verified_at`, but it omitted Laravel's usual `email_verified_at`
 * and has no place to record a linked Firebase / Google identity.
 *
 * This migration is purely additive:
 *  - `email_verified_at`  — email becomes a usable SECONDARY login identifier
 *    once proven via the custom email OTP (mobile stays the mandatory primary
 *    identifier; `phone` remains NOT NULL UNIQUE, unchanged here).
 *  - `firebase_uid`       — the canonical link to a Firebase Auth user
 *    (phone-auth or Google). Nullable + unique: existing OTP-only rows have
 *    none until they migrate; a real uid must never collide.
 *  - `google_id`          — the Google `sub` claim, kept for audit / support
 *    lookups. Nullable + unique for the same reasons.
 *  - `avatar_url`         — remote profile photo URL from a Google identity.
 *    Distinct from the existing `profile_photo` column, which stores an
 *    uploaded file path.
 *
 * Every column is nullable with no default, so every existing row keeps
 * working unchanged and nothing needs backfilling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('phone_verified_at');
            $table->string('firebase_uid')->nullable()->unique()->after('email_verified_at');
            $table->string('google_id')->nullable()->unique()->after('firebase_uid');
            $table->string('avatar_url')->nullable()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_firebase_uid_unique');
            $table->dropUnique('users_google_id_unique');
            $table->dropColumn(['email_verified_at', 'firebase_uid', 'google_id', 'avatar_url']);
        });
    }
};
