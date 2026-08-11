<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laravel's own standard "database" notification channel table (normally
// scaffolded by `artisan notifications:table`) -- this is the real "in-app"
// channel: no customer/provider app exists yet to display an inbox against
// it, but the data is there, real, and queryable the moment one does. Not a
// fabricated integration -- this is the framework's own built-in channel.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
