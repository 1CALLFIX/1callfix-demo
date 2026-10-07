<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// REF 1CF-PARTNER-PAGE-001: leads from the public /partners form, before any OTP or KYC. One row per (phone, role);
// a repeat submit updates the row instead of adding one. `status` is a plain VARCHAR (not an ENUM) so new states never
// need a schema change. `acquisition` is the F1 first-touch JSON (utm_*, click ids, landing_path, referrer_host).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 20);            // national digits, as users.phone
            $table->string('city', 120);
            $table->string('role', 32);             // a module code, validated server-side
            $table->string('status', 24)->default('new'); // new | waitlist | handed_off | converted | rejected
            $table->timestamp('consent_at');
            $table->string('consent_text', 300)->nullable(); // the wording the person agreed to
            $table->json('acquisition')->nullable();
            $table->string('source', 64)->nullable();
            $table->unsignedBigInteger('provider_id')->nullable(); // set when the person completes provider sign-up
            $table->unsignedInteger('submit_count')->default(1);
            $table->timestamps();

            $table->unique(['phone', 'role']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        // Same guard as slug_redirects: leads are personal data the owner has collected; never drop them silently.
        if (Schema::hasTable('partner_leads') && DB::table('partner_leads')->exists()) {
            throw new RuntimeException('partner_leads holds data; refusing to drop it.');
        }

        Schema::dropIfExists('partner_leads');
    }
};
