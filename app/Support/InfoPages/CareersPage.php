<?php

namespace App\Support\InfoPages;

use App\Models\JobOpening;
use App\Models\Setting;

/**
 * The public Careers page (owner 2026-11-21): /careers lists the open jobs from Admin → Careers, /careers/{slug} shows
 * one job with its application form. Page words are SiteText `careers.*` (English + Telugu, Admin → Website text &
 * language). The page can be switched off (`careers.enabled`); off = 404 and gone from the footer and sitemap.
 *
 * Partners are not staff: riders, technicians and shops join through /partners, and the page says so.
 */
final class CareersPage
{
    public const ENABLED = 'careers.enabled';

    /** CV upload limit (KB) and the private-disk folder. */
    public const CV_MAX_KB = 5120;
    public const CV_DIR = 'careers/cv';

    /** Colour of a team's badge and card stripe (Tailwind classes, all literal so the build keeps them). */
    private const TEAM_COLOURS = [
        'Sales & Partners' => ['badge' => 'bg-emerald-100 text-emerald-800', 'bar' => 'bg-emerald-500', 'icon' => 'bg-emerald-500'],
        'Customer Support' => ['badge' => 'bg-sky-100 text-sky-800', 'bar' => 'bg-sky-500', 'icon' => 'bg-sky-500'],
        'Operations' => ['badge' => 'bg-violet-100 text-violet-800', 'bar' => 'bg-violet-500', 'icon' => 'bg-violet-500'],
        'Marketing' => ['badge' => 'bg-pink-100 text-pink-800', 'bar' => 'bg-pink-500', 'icon' => 'bg-pink-500'],
        'Technology' => ['badge' => 'bg-indigo-100 text-indigo-800', 'bar' => 'bg-indigo-500', 'icon' => 'bg-indigo-500'],
        'Finance' => ['badge' => 'bg-amber-100 text-amber-800', 'bar' => 'bg-amber-500', 'icon' => 'bg-amber-500'],
    ];

    private const DEFAULT_COLOUR = ['badge' => 'bg-slate-100 text-slate-700', 'bar' => 'bg-blue-500', 'icon' => 'bg-blue-500'];

    /** Teams an admin can pick (the list above). */
    public static function teams(): array
    {
        return array_keys(self::TEAM_COLOURS);
    }

    public static function colours(?string $team): array
    {
        return self::TEAM_COLOURS[$team] ?? self::DEFAULT_COLOUR;
    }

    public static function enabled(): bool
    {
        try {
            return (string) Setting::get(self::ENABLED, '1') !== '0';
        } catch (\Throwable) {
            return false;
        }
    }

    /** schema.org JobPosting for Google job search. Pay is free text, so no baseSalary is claimed. */
    public static function jobSchema(JobOpening $job, ?string $logoUrl): array
    {
        $html = '<p>'.e((string) $job->summary).'</p>';
        foreach (['Duties' => $job->duties, 'Requirements' => $job->requirements, 'Perks' => $job->perks] as $heading => $lines) {
            if (! empty($lines)) {
                $html .= '<h3>'.$heading.'</h3><ul>'.collect($lines)->map(fn ($l) => '<li>'.e($l).'</li>')->implode('').'</ul>';
            }
        }
        if ($job->pay_text) {
            $html .= '<p>Pay: '.e($job->pay_text).'</p>';
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $job->title,
            'description' => $html,
            'datePosted' => optional($job->created_at)->toDateString(),
            'employmentType' => JobOpening::SCHEMA_TYPES[$job->employment_type] ?? 'FULL_TIME',
            'hiringOrganization' => array_filter([
                '@type' => 'Organization', 'name' => \App\Services\Seo\SeoSettings::siteName(), 'sameAs' => url('/'), 'logo' => $logoUrl,
            ]),
            'jobLocation' => [
                '@type' => 'Place',
                'address' => array_filter([
                    '@type' => 'PostalAddress', 'addressLocality' => $job->location, 'addressRegion' => $job->region, 'addressCountry' => 'IN',
                ]),
            ],
            'directApply' => true,
            'url' => $job->url(),
        ];
        if ($job->apply_by) {
            $schema['validThrough'] = $job->apply_by->endOfDay()->toIso8601String();
        }
        if (! $job->isAccepting()) {
            $schema['validThrough'] = optional($job->updated_at)->toIso8601String();
        }

        return $schema;
    }
}
