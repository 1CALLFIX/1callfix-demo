<?php

namespace App\Support\PartnerPage;

use App\Models\City;
use App\Models\KycDocumentRequirement;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Services\Parcel\ParcelSettings;
use App\Support\InfoPages\FranchisePage;
use App\Support\PartnerPage\PartnerPageSettings as P;
use App\Support\Seo\QaRows;

/**
 * Live answers for the /partners FAQ (owner 2026-11-22: "add the faq's with realtime data"). Every answer here is
 * read at render time from the same place the product reads it: role status from the module registry
 * (PartnerPageData::roles), cities from the live City scope, document lists from Admin → KYC documents, charges and
 * limits from their settings. Nothing is typed twice, so the page can never drift from what the app does.
 *
 * A fact that is not configured is never invented: its question is left out (or answered without the number).
 * Shown above the admin's own questions while "Show live answers" (show.live_faq) is on. Page cache: 60 s, and any
 * admin save flushes it, so a change shows within a minute.
 */
final class PartnerFaq
{
    /** Role card code → its document list in kyc_document_requirements.applicable_type. */
    public const ROLE_DOCS = [
        'service' => 'provider', 'parcel' => 'field_worker', 'food' => 'restaurant', 'grocery' => 'shop', 'pharmacy' => 'shop',
        'commerce' => 'shop', 'hotel' => 'hotel', 'halls' => 'hall', 'homes' => 'home', 'vehicles' => 'vehicle', 'events' => 'organiser',
    ];

    /** Business roles answered under the "businesses" tab, in card order. */
    private const BUSINESS_ROLES = ['food', 'grocery', 'pharmacy', 'hotel', 'halls', 'homes', 'vehicles', 'rental', 'events', 'commerce'];

    /**
     * @param  list<array<string, mixed>>  $roles  PartnerPageData::roles()
     * @return list<array{tab:string, q:string, a:string, live:bool, href?:string, link?:string}>
     */
    public static function live(array $roles): array
    {
        try {
            return self::build($roles);
        } catch (\Throwable $e) {
            // A missing table or a bad setting must never take the partner page down; the admin's questions still show.
            report($e);

            return [];
        }
    }

    /** @param  list<array<string, mixed>>  $roles */
    private static function build(array $roles): array
    {
        $byCode = [];
        foreach ($roles as $r) {
            $byCode[$r['code']] = $r;
        }
        $open = fn (string $c) => isset($byCode[$c]) && (($byCode[$c]['hands_off'] ?? false) || ($byCode[$c]['enquiry'] ?? false));
        $label = fn (string $c) => $byCode[$c]['label'] ?? $c;
        $cities = self::cities();
        $where = $cities === [] ? '' : ' in '.self::sentence($cities);
        $out = [];

        // ---------------------------------------------------------------- everyone
        $openNow = array_values(array_filter($roles, fn ($r) => ($r['hands_off'] ?? false) && ! ($r['enquiry'] ?? false)));
        $waiting = array_values(array_filter($roles, fn ($r) => ! ($r['hands_off'] ?? false) && ! ($r['enquiry'] ?? false)));
        if ($openNow !== [] || $waiting !== []) {
            $a = [];
            if ($openNow !== []) {
                $a[] = 'Apply now'.$where.': '.self::sentence(array_column($openNow, 'label')).'.';
            }
            if ($waiting !== []) {
                $a[] = 'Opening soon: '.self::sentence(array_column($waiting, 'label')).'. Pick one in the form to join its waitlist and we will contact you when it opens in your city.';
            }
            $out[] = self::item('everyone', 'Which roles are open right now?', implode("
", $a));
        }

        if ($cities !== []) {
            $out[] = self::item('everyone', 'Which cities can I work in?', '1CallFix is live in '.self::sentence($cities).' right now. More cities open as local franchises launch. '
                .'If your city is not in the form, choose "My city is not listed" and we will contact you when it opens.');
        }

        $mb = (float) Setting::get('kyc.max_document_size_mb', '10');
        $docs = 'The list depends on your kind of work: pick your group above to see exactly what yours needs. '
            .'You upload each one on the sign-up page as a photo or PDF (JPG, PNG or PDF, up to '.self::num($mb).' MB each), and our team checks every one.';
        if ((string) Setting::get('kyc.require_verification_video', '0') === '1') {
            $docs .= ' You also record a short verification video.';
        }
        $out[] = self::item('everyone', 'What documents do I need?', $docs);

        $approval = P::text('approval_time');
        $out[] = self::item('everyone', 'How long does approval take?', trim(($approval ? $approval.' ' : 'Our team reviews every application. ')
            .'Your KYC status shows on your partner dashboard when you sign in. If a document is not accepted, the dashboard shows why, and you upload a new one there.'), (bool) $approval);

        $min = (float) Setting::get('wallet.provider_min_payout_amount', '0');
        $pay = 'What you earn from each completed job goes to your wallet in the app. Request a payout to your verified bank account or UPI'
            .($min > 0 ? ' (minimum '.self::rupees($min).')' : '').'.';
        if ($timing = P::text('payout_timing')) {
            $pay .= ' '.$timing;
        }
        $pay .= ' On a cash job you keep the cash you collect, and the 1CallFix share is taken from your wallet.';
        $out[] = self::item('everyone', 'How and when do I get paid?', $pay);

        $terms = array_values(array_filter(array_map(fn ($r) => filled($r['terms'] ?? '') ? $r['label'].': '.$r['terms'] : null, $roles)));
        if (($line = P::commissionLine()) !== null || $terms !== []) {
            $out[] = self::item('everyone', 'How much commission does 1CallFix take?', trim(($line ?? '').' '.($terms !== [] ? implode('. ', $terms).'.' : '')));
        } else {
            $out[] = self::item('everyone', 'How much commission does 1CallFix take?', 'Your rate is agreed with 1CallFix or your local franchise when you join. '
                .'Every completed job shows in your wallet with what you earned from it.', false);
        }

        $phones = P::lines('support_phones');
        if ($phones !== []) {
            $out[] = self::item('everyone', 'Who can help me apply?', 'Call partner support on '.self::sentence($phones, 'or').'.');
        }

        if (isset($byCode[FranchisePage::ROLE])) {
            $out[] = self::item('everyone', 'Can I run 1CallFix in my own city?', 'Yes. Pick "'.$label(FranchisePage::ROLE).'" in the form below and our team will call you. '
                .trim((string) ($byCode[FranchisePage::ROLE]['blurb'] ?? '')), true, route('customer.franchise'), 'What a franchise includes');
        }

        // ---------------------------------------------------------------- service professionals
        if (isset($byCode['service'])) {
            $cats = ServiceCategory::query()->whereNull('parent_id')->where('is_active', true)->where('module', 'service')
                ->where('name', 'not like', QaRows::LIKE)
                // Only categories a customer can really book (an empty category is not work on offer).
                ->whereHas('services', fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('name')->limit(20)->pluck('name')->all();
            if ($cats !== []) {
                $out[] = self::item('service', 'Which services can I offer?', 'Customers book these services today: '.self::sentence($cats).'. '
                    .'You pick your skills when you sign up, and job offers match them.');
            }
            if ($d = self::docsAnswer('provider')) {
                $out[] = self::item('service', 'What documents does a service professional need?', $d);
            }
            if ($fee = self::visitFee()) {
                $wait = (int) Setting::get('cancellation.no_show_wait_minutes', '0');
                $calls = (int) Setting::get('cancellation.no_show_call_attempts', '0');
                $reach = $wait > 0 && $calls > 0 ? ', or you wait '.$wait.' minutes and call '.$calls.' '.($calls === 1 ? 'time' : 'times').' from the app and cannot reach them' : ', or cannot be reached';
                $out[] = self::item('service', 'When does the customer pay a visit charge?', 'Only when you reached the customer and no work was done: they say no to the quote you send in the app'
                    .$reach.'. The customer then pays a visit charge of '.$fee.' and your share goes to your wallet. If you do any work, the visit charge is never added: the customer pays for the work.');
            }
            $cap = Setting::get('cancellation.interim_cap_percent');
            if (is_numeric($cap) && (float) $cap > 0) {
                $out[] = self::item('service', 'What if I start a job but cannot finish it?', 'If work has to stop (for example, a spare part is not available), you enter one amount for the work done, '
                    .'labour and parts together. It can be at most '.self::num((float) $cap).'% of the booking total. The customer pays that amount.');
            }
        }

        // ---------------------------------------------------------------- riders and drivers
        if (isset($byCode['parcel'])) {
            $out[] = $open('parcel')
                ? self::item('riders', 'Are delivery rider jobs open?', 'Yes. Delivery riders are signing up now'.$where.'. Pick "'.$label('parcel').'" in the form below to start.')
                : self::item('riders', 'Are delivery rider jobs open?', 'Not yet. Pick "'.$label('parcel').'" in the form below to join the waitlist; we will contact you when it opens in your city.');
            if ($d = self::docsAnswer('field_worker')) {
                $out[] = self::item('riders', 'What documents does a delivery rider need?', $d);
            }
            $kg = ParcelSettings::get('parcel.max_weight_kg');
            $km = ParcelSettings::get('parcel.max_distance_km');
            if ($kg !== null && $km !== null) {
                $out[] = self::item('riders', 'What kind of parcels will I deliver?', 'Parcels up to '.self::num((float) $kg).' kg, picked up and dropped within '.self::num((float) $km).' km.');
            }
            $out[] = self::item('riders', 'Do I collect cash?', 'Some customers pay cash at pickup or at the drop, and you collect it. The 1CallFix share of that cash is taken from your wallet'
                .(($limit = ParcelSettings::get('parcel.rider_cash_due_limit')) ? '; once the share you hold reaches '.self::rupees((float) $limit).', you get no new cash orders until you settle it.' : '.'));
        }
        if (isset($byCode['taxi'])) {
            $out[] = $open('taxi')
                ? self::item('riders', 'Are cab driver jobs open?', 'Yes. Pick "'.$label('taxi').'" in the form below to start.')
                : self::item('riders', 'When will cab driver jobs open?', 'Rides are not open in any city yet. Pick "'.$label('taxi').'" in the form below to join the waitlist; we will contact you on your mobile when rides open in your city.');
        }

        // ---------------------------------------------------------------- shops, stays and owners
        $biz = array_values(array_filter(self::BUSINESS_ROLES, fn ($c) => isset($byCode[$c])));
        if ($biz !== []) {
            $bizOpen = array_values(array_filter($biz, $open));
            $bizWait = array_values(array_diff($biz, $bizOpen));
            $a = [];
            if ($bizOpen !== []) {
                $a[] = 'Apply now'.$where.': '.self::sentence(array_map($label, $bizOpen)).'.';
            }
            if ($bizWait !== []) {
                $a[] = 'Opening soon: '.self::sentence(array_map($label, $bizWait)).'. Join the waitlist and we will contact you when yours opens.';
            }
            $out[] = self::item('shops', 'Which businesses can join now?', implode("
", $a));
            foreach ($bizOpen as $code) {
                $type = self::ROLE_DOCS[$code] ?? null;
                if ($type && ($d = self::docsAnswer($type))) {
                    $out[] = self::item('shops', 'What does '.(preg_match('/^[aeiou]/i', $label($code)) ? 'an ' : 'a ').mb_strtolower($label($code)).' need?', $d);
                }
            }
            $out[] = self::item('shops', 'Can one account run more than one business?', 'Yes. One partner account can run a restaurant, a shop, a hotel or listings together. '
                .'You upload the documents each kind of business needs, once.', false);
        }

        return $out;
    }

    /** "Needed: A, B and C. If you have them, also add: D." from Admin → KYC documents; null when the list is empty. */
    public static function docsAnswer(string $type): ?string
    {
        $rows = KycDocumentRequirement::query()->where('applicable_type', $type)->whereNull('country_id')->where('is_active', true)
            ->orderBy('sort_order')->get(['label', 'is_required']);
        if ($rows->isEmpty()) {
            return null;
        }
        $need = $rows->where('is_required', true)->pluck('label')->all();
        $extra = $rows->where('is_required', false)->pluck('label')->all();
        $a = $need !== [] ? 'Needed: '.self::sentence($need).'.' : '';
        if ($extra !== []) {
            $a .= ($a !== '' ? "
" : '').'If you have them, also add: '.self::sentence($extra).'.';
        }

        return $a;
    }

    /** Live cities (switched on, with an active franchise), QA cities left out. @return list<string> */
    private static function cities(): array
    {
        return City::query()->live()->notQa()->orderBy('name')->pluck('name')->all();
    }

    private static function visitFee(): ?string
    {
        $value = (float) Setting::get('cancellation.visit_fee_value', '0');
        if ($value <= 0) {
            return null;
        }

        return Setting::get('cancellation.visit_fee_type', 'flat') === 'percent' ? self::num($value).'% of the booking' : self::rupees($value);
    }

    private static function item(string $tab, string $q, string $a, bool $live = true, ?string $href = null, ?string $link = null): array
    {
        return array_filter(['tab' => $tab, 'q' => $q, 'a' => $a, 'live' => $live, 'href' => $href, 'link' => $link], fn ($v) => $v !== null);
    }

    /** @param  list<string>  $parts */
    private static function sentence(array $parts, string $and = 'and'): string
    {
        $parts = array_values($parts);
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }
        $last = array_pop($parts);

        return implode(', ', $parts).' '.$and.' '.$last;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private static function rupees(float $n): string
    {
        return '₹'.number_format($n, fmod($n, 1.0) === 0.0 ? 0 : 2);
    }
}
