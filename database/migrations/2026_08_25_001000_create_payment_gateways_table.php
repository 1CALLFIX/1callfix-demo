<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment Gateway Manager session — admin-configurable gateway rows.
 * `credentials` is a `text` column because App\Models\PaymentGatewayConfig
 * casts it `encrypted:array` (Laravel's Crypt-backed cast, which stores an
 * encrypted ciphertext string, not raw JSON) — the first "real secret at
 * rest" cast in this schema (payment_accounts' bank details are stored
 * plain today; this migration does not touch that, only adds a new,
 * better-protected table for a new concern).
 *
 * This table starts (and, in every environment that never opens the new
 * admin screen, stays) EMPTY. PaymentGatewayManager falls back to the
 * pre-existing config('services.razorpay.*')-driven path when no row here
 * is active — see PaymentGatewayManager's own docblock. That's what makes
 * this migration additive rather than a cutover: nothing about how a
 * booking payment behaves today changes until an admin explicitly adds and
 * activates a row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // admin-given label, e.g. "Razorpay Primary"
            $table->string('driver'); // razorpay|paytm|phonepe -- see PaymentGatewayManager::ACTIVATABLE_DRIVERS for which can actually go live
            $table->text('credentials')->nullable(); // encrypted:array cast on the model -- never plain text
            $table->string('mode')->default('test'); // test|live -- informational, same signal Razorpay's own key prefix already carries
            $table->boolean('is_active')->default(false);
            // Higher priority is tried first. Ties broken by id ascending
            // (first configured wins) -- see PaymentGatewayManager::active().
            $table->integer('priority')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
