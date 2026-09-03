<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partner benefits — the admin-editable "why partner with us" list shown on
 * the public /coming-soon/partners landing page. Structurally a sibling of
 * `faqs`: a small flat table (icon, title, description, sort_order,
 * is_active) managed from the existing Website / CMS admin screen
 * (App\Livewire\Cms\Manage), NOT a new admin pattern.
 *
 * Provider-facing content only. It is deliberately separate from the
 * customer "Why choose 1CallFix" trust block on the homepage — a different
 * audience and a different message — so nothing existing is touched.
 *
 * `icon` holds one key from App\Models\PartnerBenefit::ICONS, each of which
 * maps to an already-shipped <x-icon> glyph. No icon upload, no free text.
 *
 * The four starter rows are inserted here (guarded on an empty table, the
 * same discipline as the other data-bearing migrations in this repo) so a
 * fresh deploy has real content without a separate manual seeder run. They
 * are ordinary editable rows afterwards — edit or delete them freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_benefits', function (Blueprint $table) {
            $table->id();
            $table->string('icon')->default('sparkles');
            $table->string('title');
            $table->string('description', 500);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        if (DB::table('partner_benefits')->count() === 0) {
            $now = now();

            DB::table('partner_benefits')->insert([
                [
                    'icon' => 'wallet',
                    'title' => 'Steady, well-paid work',
                    'description' => 'Get matched with jobs in your area for the trades you actually do. Prices are set by the platform and shown before you accept.',
                    'sort_order' => 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'icon' => 'clock',
                    'title' => 'Work on your schedule',
                    'description' => 'Go online when you want the work, offline when you don\'t. You choose which job offers to accept.',
                    'sort_order' => 2,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'icon' => 'banknotes',
                    'title' => 'Clear, on-time payouts',
                    'description' => 'Every job\'s earnings and platform commission are itemised in your dashboard, and settlements are tracked end to end.',
                    'sort_order' => 3,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'icon' => 'shield',
                    'title' => 'A verified, protected profile',
                    'description' => 'Your documents are checked before you go live, and the one-time start and completion codes protect both you and the customer on every visit.',
                    'sort_order' => 4,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_benefits');
    }
};
