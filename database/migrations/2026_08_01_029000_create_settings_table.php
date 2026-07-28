<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P1
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete(); // null = global
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['franchise_id','key']);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
