<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One push token per account per Android app (customer | rider | partner), owner rule 2026-11-06: one account can
 * use the Partner app and the Rider app on one phone, and each app must keep its own alerts. users.fcm_token stays
 * for browsers (web push) and is the fallback for an account that has no app token yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 20);
            $table->string('token', 255);
            $table->timestamps();
            $table->unique(['user_id', 'app']);
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_push_tokens');
    }
};
