<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P3
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise_applications', function (Blueprint $table) {
            $table->id();
            $table->string('applicant_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('proposed_city');
            $table->text('notes')->nullable();
            $table->enum('status', ['new','under_review','approved','rejected'])->default('new');
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_applications');
    }
};
