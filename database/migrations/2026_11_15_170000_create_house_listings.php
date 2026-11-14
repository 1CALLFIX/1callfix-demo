<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Houses for rent (owner 2026-11-14: "monthly house rent is meant", nightly stays are Hotels). Part of the one Rental
 * module (kind `properties` in Rental Settings).
 * - house_listings: a house, flat, portion, room or PG an owner lets by the month. draft → in_review → approved |
 *   rejected. A live listing's photos / description / address wait in pending_changes for the team.
 * - house_visits: a customer asks to see the home on a day and time; the owner accepts or declines.
 * - house_contact_views: a signed-in customer saw the owner's number (daily limit, Rental Settings).
 * - the house owner document list and the `homes.manage` admin permission.
 */
return new class extends Migration
{
    private const DOCUMENTS = [
        ['id_proof', 'Owner photo ID (Aadhaar, PAN, passport or voter ID)', true],
        ['business_proof', 'Proof you own or manage the home (property tax receipt, electricity bill or agreement)', true],
        ['bank_details', 'Bank details (cancelled cheque or passbook first page)', false],
    ];

    public function up(): void
    {
        Schema::create('house_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->string('slug', 190)->unique();
            $table->string('home_type', 80)->nullable();
            $table->string('size', 40)->nullable(); // 1 RK, 1 BHK, 2 BHK... (Rental Settings list)
            $table->unsignedInteger('area')->nullable(); // in the area unit of Rental Settings (sq ft / sq m / sq yd)
            $table->string('floor', 30)->nullable();
            $table->string('facing', 30)->nullable();
            $table->string('furnishing', 60)->nullable();
            $table->json('tenants')->nullable();
            $table->decimal('rent', 12, 2)->nullable();
            $table->decimal('deposit', 12, 2)->nullable();
            $table->decimal('maintenance', 12, 2)->nullable();
            $table->date('available_from')->nullable();
            $table->string('landmark', 160)->nullable(); // shown to everyone
            $table->string('address_line', 500)->nullable(); // shown only after a visit is accepted
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('amenities')->nullable();
            $table->json('rule_list')->nullable();
            $table->text('about')->nullable();
            $table->string('cover_image')->nullable();
            $table->json('photos')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('rented_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('pending_changes')->nullable();
            $table->timestamp('pending_requested_at')->nullable();
            $table->string('pending_rejection', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'is_active', 'franchise_id']);
        });

        Schema::create('house_visits', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('house_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->date('visit_date');
            $table->string('slot', 60);
            $table->string('customer_name', 120);
            $table->string('customer_phone', 20);
            $table->string('tenant_type', 60)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('status', 20)->default('requested'); // requested → accepted | declined | cancelled | expired
            $table->string('owner_note', 300)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['house_listing_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('house_contact_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['house_listing_id', 'customer_id']);
            $table->index(['customer_id', 'created_at']);
        });

        $now = now();
        foreach (self::DOCUMENTS as $i => [$doc, $label, $required]) {
            if (! DB::table('kyc_document_requirements')->where('applicable_type', 'home')->where('document_type', $doc)->whereNull('country_id')->exists()) {
                DB::table('kyc_document_requirements')->insert([
                    'applicable_type' => 'home', 'document_type' => $doc, 'label' => $label, 'is_required' => $required,
                    'country_id' => null, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if (! DB::table('permissions')->where('slug', 'homes.manage')->exists()) {
            DB::table('permissions')->insert([
                'slug' => 'homes.manage', 'label' => 'Houses for rent: approve listings and their changes, feature or switch off a listing, see visit requests',
                'group' => 'Rental', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $permissionId = DB::table('permissions')->where('slug', 'homes.manage')->value('id');
        // Super Admin, and every role that already approves function halls (the same team checks homes).
        $sourceId = DB::table('permissions')->where('slug', 'halls.manage')->value('id');
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
        $permissionId = DB::table('permissions')->where('slug', 'homes.manage')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        DB::table('kyc_document_requirements')->where('applicable_type', 'home')->delete();
        Schema::dropIfExists('house_contact_views');
        Schema::dropIfExists('house_visits');
        Schema::dropIfExists('house_listings');
    }
};
