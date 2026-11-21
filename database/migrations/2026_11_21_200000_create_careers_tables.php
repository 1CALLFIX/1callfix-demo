<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Careers page (owner 2026-11-21): the jobs 1CallFix is hiring for (`job_openings`, shown on /careers and
 * /careers/{slug}) and the people who applied (`job_applications`, Admin → Job applications). One application per
 * (mobile, job): a repeat submit updates the row. A blank job = "any role". CVs live on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('slug', 160)->unique();
            $table->string('team', 60)->nullable();
            $table->string('employment_type', 20)->default('full_time'); // full_time | part_time | internship | contract
            $table->string('location', 100)->default('Nellore');
            $table->string('region', 100)->nullable();
            $table->unsignedSmallInteger('openings')->default(1);
            $table->string('pay_text', 120)->nullable();
            $table->text('summary')->nullable();
            $table->json('duties')->nullable();
            $table->json('requirements')->nullable();
            $table->json('perks')->nullable();
            $table->boolean('needs_two_wheeler')->default(false);
            $table->date('apply_by')->nullable();
            $table->boolean('is_open')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_opening_id')->nullable()->constrained('job_openings')->nullOnDelete();
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('email', 150)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('experience', 10)->nullable(); // fresher | 1-2 | 3-5 | 5+
            $table->string('current_work', 150)->nullable();
            $table->boolean('two_wheeler')->nullable();
            $table->string('cv_path')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->text('admin_notes')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_text', 500)->nullable();
            $table->json('acquisition')->nullable();
            $table->unsignedInteger('submit_count')->default(1);
            $table->timestamps();

            $table->unique(['phone', 'job_opening_id'], 'job_applications_phone_job_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('job_openings');
    }
};
