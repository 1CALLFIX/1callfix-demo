<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Function halls R1 (owner 2026-11-13: "start with events and then start with rentals"; halls first, settings left to
 * us). Its own module `halls`, shown under Rental in admin and as "Halls" to customers.
 * - modules row `halls` (not implemented yet: no customer can book until R2 ships and an admin switches it on).
 * - halls: the hall an owner lists (one at sign-up, more from the portal). draft → in_review → approved | rejected.
 *   A live hall's name / address / photos / description wait in pending_changes for the team; the hall stays bookable.
 * - hall_slots: what a customer books (Full day, Morning, Evening...), each with its own time and price.
 * - hall_blocked_dates: days (or one slot of a day) the owner already sold outside 1CallFix.
 * - the hall owner document list, and the `halls.manage` admin permission.
 */
return new class extends Migration
{
    private const DOCUMENTS = [
        ['id_proof', 'Owner photo ID (Aadhaar, PAN, passport or voter ID)', true],
        ['bank_details', 'Bank details (cancelled cheque or passbook first page)', true],
        ['business_proof', 'Proof you run the hall (property tax receipt, lease, or trade licence)', true],
        ['tax_document', 'GST certificate', false],
    ];

    public function up(): void
    {
        Schema::create('halls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->string('hall_type', 60)->nullable();
            $table->text('about')->nullable();
            $table->string('address_line', 500)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->unsignedInteger('seating_capacity')->nullable();
            $table->unsignedInteger('floating_capacity')->nullable();
            $table->boolean('is_ac')->default(false);
            $table->unsignedInteger('parking_cars')->nullable();
            $table->unsignedSmallInteger('rooms')->nullable(); // bride / groom / guest rooms
            $table->json('amenities')->nullable();
            $table->string('cover_image')->nullable();
            $table->json('photos')->nullable();
            $table->text('rules')->nullable();
            $table->decimal('deposit_amount', 10, 2)->nullable(); // refundable, owner sets it; null = none
            $table->string('gst_number', 15)->nullable();
            // draft → in_review → approved | rejected ("Needs changes", editable again)
            $table->string('status', 20)->default('draft');
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_active')->default(false); // the owner's on/off once approved (and test-accounts disable)
            $table->boolean('is_featured')->default(false);
            $table->json('pending_changes')->nullable();
            $table->timestamp('pending_requested_at')->nullable();
            $table->string('pending_rejection', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'is_active']);
        });

        Schema::create('hall_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hall_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->time('start_time');
            $table->time('end_time'); // may be earlier than start: ends the next day (e.g. 6 PM – 2 AM)
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('hall_blocked_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hall_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('hall_slot_id')->nullable()->constrained()->cascadeOnDelete(); // null = the whole day
            $table->string('note', 200)->nullable();
            $table->timestamps();
            $table->index(['hall_id', 'date']);
        });

        $now = now();
        if (! DB::table('modules')->where('code', 'halls')->exists()) {
            DB::table('modules')->insert([
                'code' => 'halls', 'name' => 'Function Halls', 'sort_order' => (int) DB::table('modules')->max('sort_order') + 1,
                'is_active' => true, 'is_implemented' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::DOCUMENTS as $i => [$doc, $label, $required]) {
            if (! DB::table('kyc_document_requirements')->where('applicable_type', 'hall')->where('document_type', $doc)->whereNull('country_id')->exists()) {
                DB::table('kyc_document_requirements')->insert([
                    'applicable_type' => 'hall', 'document_type' => $doc, 'label' => $label, 'is_required' => $required,
                    'country_id' => null, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if (! DB::table('permissions')->where('slug', 'halls.manage')->exists()) {
            DB::table('permissions')->insert([
                'slug' => 'halls.manage', 'label' => 'Function halls: approve halls and their changes, feature or switch off a hall',
                'group' => 'Rental', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $permissionId = DB::table('permissions')->where('slug', 'halls.manage')->value('id');
        // Super Admin, and every role that already approves hotels (the same people check halls).
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
        $permissionId = DB::table('permissions')->where('slug', 'halls.manage')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        DB::table('kyc_document_requirements')->where('applicable_type', 'hall')->delete();
        DB::table('modules')->where('code', 'halls')->delete();
        Schema::dropIfExists('hall_blocked_dates');
        Schema::dropIfExists('hall_slots');
        Schema::dropIfExists('halls');
    }
};
