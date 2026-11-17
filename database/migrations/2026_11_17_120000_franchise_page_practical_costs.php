<?php

use App\Support\InfoPages\FranchisePage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Franchise page (owner 2026-11-17): the "what it costs to start each business on your own" section moves from the
 * ₹66–96 lakh figures to practical planning ranges, and a "Revenue potential" section is added. Where the Super Admin
 * has already saved the page, saved values win over new defaults, so this swaps the section ONLY where it is still our
 * original wording (word for word). Anything the Super Admin edited is left alone. The revenue section and the dashboard
 * switches need nothing here: a key missing from the saved content falls back to the new default.
 */
return new class extends Migration
{
    private const KEY = 'franchise_page.content';

    private const OLD = [
        'alone_title' => 'What it costs to start each business on your own',
        'alone_intro' => 'Every business needs its own app, team, marketing and time. With a franchise, all of that is ready on day one.',
        'alone_cost_label' => 'Set-up cost on your own',
        'alone_time_label' => 'Time to profit',
        'alone_total_label' => 'All seven on your own',
        'alone_total' => '₹66–96 Lakhs',
        'alone_us_label' => '1CallFix All-in-One franchise, every business',
        'alone_note' => 'Set-up costs and timelines are our estimates for a business started independently in an Indian city. They help you plan; they are not a promise of income.',
    ];

    private const OLD_COSTS = ['₹7–10 Lakhs', '₹15–20 Lakhs', '₹10–15 Lakhs', '₹8–12 Lakhs', '₹10–15 Lakhs', '₹8–12 Lakhs', '₹8–12 Lakhs'];

    public function up(): void
    {
        $row = DB::table('settings')->where('scope_type', 'global')->whereNull('scope_id')->where('key', self::KEY)->first();
        $content = $row ? json_decode((string) $row->value, true) : null;
        if (! is_array($content)) {
            return; // never saved: the new defaults apply on their own
        }

        $new = [
            'alone_title' => 'What it takes to start each business on your own',
            'alone_intro' => 'Every business needs its own team, marketing and working money. With a franchise, the apps, brand and payments are ready on day one.',
            'alone_cost_label' => 'Set-up on your own',
            'alone_time_label' => 'Running cost a month',
            'alone_total_label' => 'Seven separate businesses, each on its own',
            'alone_total' => '₹30–59 Lakhs',
            'alone_us_label' => '1CallFix All-in-One franchise fee, every business',
            'alone_note' => 'Planning estimates for a small independent operator in a Tier-2 or Tier-3 Indian city, with a modest working reserve and without buying property. They are not quotes and vary by city and size. The franchise fee does not include your own local running costs: see the revenue section below.',
        ];
        foreach (self::OLD as $key => $old) {
            if (array_key_exists($key, $content) && trim((string) $content[$key]) === $old) {
                $content[$key] = $new[$key];
            }
        }

        // The seven cards: replaced only while every card still carries our original set-up figure.
        if (is_array($content['alone'] ?? null) && count($content['alone']) === 7
            && array_map(fn ($c) => trim((string) ($c['cost'] ?? '')), $content['alone']) === self::OLD_COSTS) {
            $content['alone'] = FranchisePage::aloneDefaults();
        }

        DB::table('settings')->where('id', $row->id)->update(['value' => json_encode($content, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        cache()->forget('setting:global::'.self::KEY);
    }

    public function down(): void
    {
        // The new wording is ordinary page content now; the Super Admin edits it on Admin → Franchise page.
    }
};
