<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Franchise page (owner 2026-11-14): fee plans are now shown on the page. Where the Super Admin already saved the page
 * content, the saved "What you get" and FAQ lists win over the new defaults, so the four new "what you get" lines and
 * the fee answers would never show. This adds them to the saved version, carefully:
 *  - a "what you get" line is added only if it is not there yet and the list has room (10);
 *  - the "How much does a franchise cost?" answer is replaced only where it is still our original wording;
 *  - the "Can I run only one or two businesses?" question is added only if missing and the list has room (15).
 * Nothing the Super Admin wrote is changed or removed. No saved content = nothing to do (the defaults apply).
 */
return new class extends Migration
{
    private const KEY = 'franchise_page.content';

    private const SUPPORT = [
        'Training for local service set-up and partner onboarding',
        'Marketing material: banners, brochures and WhatsApp creatives',
        'Franchise certificate and area rights',
        'One month of help hiring your city coordinator',
    ];

    private const COST_Q = 'How much does a franchise cost?';
    private const COST_OLD = 'It depends on the city. We share the investment and fees in writing on your call with our team, before you commit to anything.';
    private const COST_NEW = 'See the plans on this page: all businesses, any two, or add one later. You pay a ₹50,000 booking advance to lock your city and the balance within 20–25 days of signing the Letter of Intent. Final fees are written in your franchise agreement.';
    private const PICK_Q = 'Can I run only one or two businesses?';
    private const PICK_A = 'Yes. Pick only the businesses you want; you pay only for those and can add more later. Your city is switched on for exactly the businesses in your agreement.';

    public function up(): void
    {
        $row = DB::table('settings')->where('scope_type', 'global')->whereNull('scope_id')->where('key', self::KEY)->first();
        $content = $row ? json_decode((string) $row->value, true) : null;
        if (! is_array($content)) {
            return;
        }

        if (is_array($content['support'] ?? null)) {
            $have = array_map(fn ($r) => trim((string) ($r['text'] ?? '')), $content['support']);
            foreach (self::SUPPORT as $line) {
                if (count($content['support']) < 10 && ! in_array($line, $have, true)) {
                    $content['support'][] = ['text' => $line];
                }
            }
        }

        if (is_array($content['faq'] ?? null)) {
            $questions = [];
            foreach ($content['faq'] as $i => $f) {
                $questions[] = trim((string) ($f['q'] ?? ''));
                if (trim((string) ($f['q'] ?? '')) === self::COST_Q && trim((string) ($f['a'] ?? '')) === self::COST_OLD) {
                    $content['faq'][$i]['a'] = self::COST_NEW;
                }
            }
            if (count($content['faq']) < 15 && ! in_array(self::PICK_Q, $questions, true)) {
                $content['faq'][] = ['q' => self::PICK_Q, 'a' => self::PICK_A];
            }
        }

        DB::table('settings')->where('id', $row->id)->update(['value' => json_encode($content, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        cache()->forget('setting:global::'.self::KEY);
    }

    public function down(): void
    {
        // The added lines are ordinary page content now; the Super Admin removes them on Admin → Franchise page.
    }
};
