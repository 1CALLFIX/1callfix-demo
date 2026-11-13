<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events E1 (owner 2026-11-13): event ticket sales ("Year End Bash") as their own module, next to Rental.
 * - modules row `events` (not implemented yet: no customer can buy until E2 ships and an admin switches it on).
 * - event_organisers: the organiser business a partner signs up as (waits for approval, like a hotel).
 * - events + event_ticket_types: an event the organiser builds in the portal and sends for review; only an approved
 *   event can ever go on sale.
 * - the organiser document list, and the `events.manage` admin permission.
 */
return new class extends Migration
{
    private const DOCUMENTS = [
        ['id_proof', 'Organiser photo ID (Aadhaar, PAN, passport or voter ID)', true],
        ['bank_details', 'Bank details (cancelled cheque or passbook first page)', true],
        ['business_proof', 'Business registration, or a letter from the venue confirming your event', false],
        ['tax_document', 'GST certificate', false],
    ];

    public function up(): void
    {
        Schema::create('event_organisers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->text('about')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('address_line', 500)->nullable();
            $table->string('gst_number', 15)->nullable();
            $table->string('logo')->nullable();
            $table->string('approval_status', 20)->default('pending'); // pending | approved | rejected
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['approval_status', 'is_active']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_organiser_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 150);
            $table->string('slug', 180)->unique();
            $table->string('category', 60)->nullable();
            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();
            $table->json('photos')->nullable();
            $table->string('venue_name', 150)->nullable();
            $table->string('venue_address', 500)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            // Optional link to a hotel listed on 1CallFix (a function hall link comes with Rental).
            $table->foreignId('accommodation_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedTinyInteger('min_age')->nullable(); // 18 / 21; null = all ages
            $table->text('terms')->nullable();
            // draft → in_review → approved (on sale) | rejected; approved → cancelled | completed
            $table->string('status', 20)->default('draft');
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });

        Schema::create('event_ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('sold')->default(0);
            $table->unsignedTinyInteger('admits')->default(1); // a Couple pass lets 2 people in
            $table->unsignedTinyInteger('max_per_order')->default(10);
            $table->dateTime('sale_ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        if (! DB::table('modules')->where('code', 'events')->exists()) {
            DB::table('modules')->insert([
                'code' => 'events', 'name' => 'Events', 'sort_order' => (int) DB::table('modules')->max('sort_order') + 1,
                'is_active' => true, 'is_implemented' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::DOCUMENTS as $i => [$doc, $label, $required]) {
            if (! DB::table('kyc_document_requirements')->where('applicable_type', 'organiser')->where('document_type', $doc)->whereNull('country_id')->exists()) {
                DB::table('kyc_document_requirements')->insert([
                    'applicable_type' => 'organiser', 'document_type' => $doc, 'label' => $label, 'is_required' => $required,
                    'country_id' => null, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if (! DB::table('permissions')->where('slug', 'events.manage')->exists()) {
            DB::table('permissions')->insert([
                'slug' => 'events.manage', 'label' => 'Events: approve organisers and events, feature or stop an event',
                'group' => 'Events', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $permissionId = DB::table('permissions')->where('slug', 'events.manage')->value('id');
        // Super Admin, and every role that already approves hotels (the same people approve organisers).
        $sourceId = DB::table('permissions')->where('slug', 'accommodations.manage')->value('id');
        $roleIds = DB::table('permission_role')->where('permission_id', $sourceId)->pluck('role_id')
            ->push(DB::table('roles')->where('slug', 'super_admin')->value('id'))->filter()->unique();
        foreach ($roleIds as $roleId) {
            if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'events.manage')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        DB::table('kyc_document_requirements')->where('applicable_type', 'organiser')->delete();
        DB::table('modules')->where('code', 'events')->delete();
        Schema::dropIfExists('event_ticket_types');
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_organisers');
    }
};
