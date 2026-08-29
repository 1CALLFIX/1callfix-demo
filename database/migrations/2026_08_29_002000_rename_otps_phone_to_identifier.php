<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auth rebuild — the shared OTP engine (`otps` / OtpService) is repurposed
 * from LOGIN (now handled by Firebase phone auth + password) to the custom
 * EMAIL verification / password-reset channel.
 *
 * The column was named `phone` back when the only planned purpose was a
 * phone login code (2026_08_01_004000 / 2026_08_13_001000). It now holds a
 * phone number OR an email address depending on `channel`, so `identifier`
 * is the honest name. `channel` already exists (was always 'sms', becomes
 * 'email' for the new rows).
 *
 * Sequence: drop the old composite index by its explicit name, rename the
 * column, recreate the index under the new name. Splitting the three
 * operations into separate Schema::table calls keeps SQLite's table-rebuild
 * strategy (used by the test suite, per phpunit.xml) from tripping over a
 * stale index reference mid-rebuild.
 *
 * Login OTP had, by design, exactly these consumers, all migrated in this
 * same branch: API\AuthController::{requestOtp,verifyOtp} (demoted to
 * verification only), Livewire Customer\Auth\Login (replaced by password
 * login). No production `otps` rows are login codes that outlive their few
 * minutes of validity, so there is nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex('otps_phone_purpose_status_idx');
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->renameColumn('phone', 'identifier');
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->index(['identifier', 'purpose', 'status'], 'otps_identifier_purpose_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex('otps_identifier_purpose_status_idx');
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->renameColumn('identifier', 'phone');
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->index(['phone', 'purpose', 'status'], 'otps_phone_purpose_status_idx');
        });
    }
};
