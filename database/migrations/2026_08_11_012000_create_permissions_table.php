<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v1 permission catalog, grounded in the admin actions that actually exist
// today (routes/admin.php + the Livewire methods/Actions behind them) —
// not a speculative full list. New permissions get added the same way new
// admin capabilities get added: one row per real capability, when it's built.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // e.g. bookings.cancel
            $table->string('label');
            $table->string('group')->default('general'); // UI grouping only
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
