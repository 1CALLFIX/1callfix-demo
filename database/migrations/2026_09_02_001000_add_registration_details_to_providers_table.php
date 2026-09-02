<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PHASE PSR — public provider self-registration. The self-registration form
// (App\Livewire\Provider\Auth\Register) captures the address the applicant
// typed plus the geolocation pin it resolved a zone from. `providers` had
// NO column for a registration / base address: `current_lat`/`current_lng`
// is the LIVE online GPS fix written by SetProviderOnlineStatusAction and
// must not be reused for a fixed address (it would corrupt dispatch
// distance maths).
//
// Additive, all nullable, backfill-null. Nothing in dispatch reads these —
// they are review context for the admin KYC queue and, for an
// out-of-coverage application (no zone whose radius reaches the pin), the
// only record of where the applicant actually is so an operator can
// confirm / relocate the franchise-zone placement by hand. The CSV
// bulk-pre-register path never collected an address and still doesn't;
// these stay null there.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('registration_address')->nullable()->after('zone_id');
            $table->decimal('registration_lat', 10, 7)->nullable()->after('registration_address');
            $table->decimal('registration_lng', 10, 7)->nullable()->after('registration_lat');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn(['registration_address', 'registration_lat', 'registration_lng']);
        });
    }
};
