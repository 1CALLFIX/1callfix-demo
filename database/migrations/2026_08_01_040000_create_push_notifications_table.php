<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: P2
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete(); // null = all franchises
            $table->string('title');
            $table->text('body');
            $table->enum('audience', ['customers','providers','all'])->default('customers');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_notifications');
    }
};
