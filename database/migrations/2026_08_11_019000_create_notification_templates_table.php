<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reusable content for the Notification Center's composer. {{variable}}
// placeholders are substituted per-recipient at send time by
// TemplateRenderer (name/phone today; more become available as the
// underlying data they'd come from — booking, coupon, etc. — is already
// on the campaign/recipient by the time a template is rendered).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('title_template');
            $table->text('body_template');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
