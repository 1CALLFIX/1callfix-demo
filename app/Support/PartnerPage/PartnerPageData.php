<?php

namespace App\Support\PartnerPage;

use App\Models\Module;
use App\Services\ModuleActivationService;
use App\Support\PartnerPage\PartnerPageSettings as P;

/**
 * REF 1CF-PARTNER-PAGE-001 — everything the /partners view renders, assembled from the two allowed sources:
 * the module registry (role cards) and the settings store (all text and lists). The view holds no wording.
 */
final class PartnerPageData
{
    /**
     * Role cards from the module registry. `live` is the registry's own answer (ModuleActivationService), read live;
     * a module that is not live is "Opening soon" and its leads go to the waiting list. Only the service role can
     * continue into the existing provider sign-up.
     *
     * @return list<array{code:string, label:string, blurb:string, live:bool, hands_off:bool}>
     */
    public static function roles(): array
    {
        $registry = Module::query()->whereIn('code', P::ROLE_ORDER)->get(['code'])->pluck('code')->all();
        $hidden = P::hiddenModules();
        $wording = P::roleWording();
        $activation = app(ModuleActivationService::class);

        $cards = [];
        foreach (P::ROLE_ORDER as $code) {
            if (! in_array($code, $registry, true) || in_array($code, $hidden, true)) {
                continue;
            }
            $live = $activation->isActive($code);
            $cards[] = [
                'code' => $code,
                'label' => $wording[$code]['label'],
                'blurb' => $wording[$code]['blurb'],
                'live' => $live,
                'hands_off' => $code === 'service' && $live,
            ];
        }

        return $cards;
    }

    /** @return array<string, mixed> */
    public static function build(): array
    {
        $faqByTab = [];
        foreach (P::faq() as $item) {
            $faqByTab[$item['tab']][] = $item;
        }
        $tabs = [];
        foreach (P::FAQ_TABS as $key => $label) {
            if (! empty($faqByTab[$key])) {
                $tabs[$key] = ['label' => $label, 'items' => $faqByTab[$key]];
            }
        }

        $phones = array_map(fn ($line) => ['text' => $line, 'tel' => preg_replace('/[^0-9+]/', '', $line)], P::lines('support_phones'));

        return [
            'heroTitle' => P::text('hero.title'),
            'heroSubtitle' => P::text('hero.subtitle'),
            'heroCta' => P::text('hero.cta_label'),
            'band' => P::on('show.nellore_band') ? P::text('nellore_band.text') : null,
            'roles' => self::roles(),
            'steps' => P::on('show.steps') ? P::steps() : [],
            'needs' => P::lines('needs'),
            'benefits' => P::on('show.benefits') ? P::benefits() : [],
            'faqTabs' => P::on('show.faq') ? $tabs : [],
            'claims' => collect(P::CLAIM_TEXT)->filter(fn ($text, $key) => P::on($key))->mapWithKeys(fn ($text, $key) => [substr($key, 7) => $text])->all(),
            'commission' => P::commissionLine(),
            'payoutTiming' => P::text('payout_timing'),
            'approvalTime' => P::text('approval_time'),
            'phones' => $phones,
            'footerContact' => P::text('footer_contact'),
            'androidUrl' => P::text('store.android_url'),
            'iosUrl' => P::text('store.ios_url'),
            'consentText' => P::text('form.consent_text'),
            'seoTitle' => P::text('seo.title') ?: 'Partner with us',
            'seoDescription' => P::text('seo.description') ?: P::text('hero.subtitle'),
        ];
    }
}
