<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// F3: per-city page copy a franchise may edit (title, meta description, intro). Never a slug.
// subject_type: city | category | service. subject_id: 0 for the city landing page itself.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_page_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id')->default(0);
            $table->string('title', 160)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->text('intro')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['city_id', 'subject_type', 'subject_id'], 'city_page_contents_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('city_page_contents') && DB::table('city_page_contents')->exists()) {
            throw new RuntimeException('city_page_contents holds data; refusing to drop it.');
        }

        Schema::dropIfExists('city_page_contents');
    }
};
