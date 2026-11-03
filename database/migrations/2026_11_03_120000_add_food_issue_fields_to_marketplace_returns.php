<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Food F4 — a problem with a food order (missing / wrong / spilled / poor quality) is a marketplace_returns row with
 * no item coming back. The admin picks whose fault it was when accepting; the refund is then split by the Food
 * Settings shares: the restaurant's part comes out of its held earnings, the platform pays the rest. A "damaged on
 * the way" problem records a strike against the rider who delivered it (no money taken from the rider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->string('fault', 20)->nullable()->after('decided_at'); // restaurant | transit | unclear (food only)
            $table->foreignId('field_worker_id')->nullable()->after('fault')->constrained('field_workers')->nullOnDelete(); // rider who delivered
            $table->decimal('restaurant_paid', 10, 2)->nullable()->after('field_worker_id');
            $table->decimal('platform_paid', 10, 2)->nullable()->after('restaurant_paid');
            $table->index(['field_worker_id', 'fault']);
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->dropIndex(['field_worker_id', 'fault']);
            $table->dropConstrainedForeignId('field_worker_id');
            $table->dropColumn(['fault', 'restaurant_paid', 'platform_paid']);
        });
    }
};
