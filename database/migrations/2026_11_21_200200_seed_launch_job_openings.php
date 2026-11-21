<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Careers page (owner 2026-11-21): the three jobs needed to go to market in Nellore. Inserted only when there are no
 * jobs yet, so it never overwrites the Super Admin's own edits. Pay is left blank (nothing invented): the owner fills it
 * in on Admin → Careers if they want it shown. Digital marketing is part of the City Operations role (owner decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('job_openings')->exists()) {
            return;
        }
        $now = now();
        $json = fn (array $lines) => json_encode($lines, JSON_UNESCAPED_UNICODE);
        $jobs = [
            [
                'title' => 'Partner Onboarding Executive (Field)',
                'slug' => 'partner-onboarding-executive',
                'team' => 'Sales & Partners',
                'openings' => 3,
                'needs_two_wheeler' => true,
                'summary' => 'Meet shops, restaurants, bakeries, hotels and function halls across Nellore, show them how easy it is to sell on 1CallFix, and help them go live.',
                'duties' => [
                    'Visit local businesses in your area every day and explain 1CallFix in Telugu',
                    'Show the short walk-through videos and help the owner sign up on their phone',
                    'Help them add their photos, menu or products and their prices',
                    'Follow up until their shop is approved and live',
                    'Share a simple daily report of the businesses you met',
                ],
                'requirements' => [
                    'Speaks Telugu well, basic English',
                    'Own two-wheeler with a valid driving licence',
                    'A smartphone, and comfortable using apps',
                    'Friendly and confident meeting shop owners',
                    'Freshers welcome; sales or field experience is a plus',
                ],
                'perks' => [
                    'Training on the app before you start',
                    'Work in your own city',
                    'Grow with a new Nellore company',
                ],
            ],
            [
                'title' => 'Customer Support Executive (Telugu & English)',
                'slug' => 'customer-support-executive',
                'team' => 'Customer Support',
                'openings' => 2,
                'needs_two_wheeler' => false,
                'summary' => 'Be the friendly voice of 1CallFix. Help customers and partners on calls and WhatsApp, in Telugu and English. Shift work.',
                'duties' => [
                    'Answer calls and WhatsApp messages from customers and partners',
                    'Help people book services, place orders and track them',
                    'Solve problems with the partner and keep the customer updated',
                    'Record every complaint and follow up until it is closed',
                    'Call new customers and partners to check everything went well',
                ],
                'requirements' => [
                    'Fluent Telugu and good spoken English',
                    'Patient, polite and a good listener',
                    'Basic computer and smartphone skills',
                    'Ready to work in shifts',
                    'Freshers welcome; call-centre or support experience is a plus',
                ],
                'perks' => [
                    'Training on the app before you start',
                    'Work in your own city',
                    'Grow with a new Nellore company',
                ],
            ],
            [
                'title' => 'City Operations & Marketing Manager',
                'slug' => 'city-operations-marketing-manager',
                'team' => 'Operations',
                'openings' => 1,
                'needs_two_wheeler' => true,
                'summary' => 'Run 1CallFix in Nellore day to day and grow it: lead the field and support team, keep quality high, and bring in customers with local marketing.',
                'duties' => [
                    'Run daily operations across services, shops, food, hotels, rentals and parcel',
                    'Lead and train the partner onboarding and customer support team',
                    'Check and approve new partners, and keep service quality high',
                    'Handle escalations from customers and partners',
                    'Run local marketing: Instagram, WhatsApp, Google Business Profile, offers and Telugu content',
                    'Share a weekly report of orders, partners and customer feedback',
                ],
                'requirements' => [
                    '2+ years in operations, sales or marketing (local business, delivery or a start-up)',
                    'Telugu and English, spoken and written',
                    'Has led a small team',
                    'Comfortable with social media and simple numbers',
                    'Own two-wheeler',
                ],
                'perks' => [
                    'Lead the city from day one',
                    'Grow with a new Nellore company',
                ],
            ],
        ];
        foreach ($jobs as $i => $job) {
            DB::table('job_openings')->insert([
                'title' => $job['title'], 'slug' => $job['slug'], 'team' => $job['team'], 'employment_type' => 'full_time',
                'location' => 'Nellore', 'region' => 'Andhra Pradesh', 'openings' => $job['openings'], 'pay_text' => null,
                'summary' => $job['summary'], 'duties' => $json($job['duties']), 'requirements' => $json($job['requirements']),
                'perks' => $json($job['perks']), 'needs_two_wheeler' => $job['needs_two_wheeler'], 'apply_by' => null,
                'is_open' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('job_openings')->whereIn('slug', ['partner-onboarding-executive', 'customer-support-executive', 'city-operations-marketing-manager'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('job_applications')->whereColumn('job_applications.job_opening_id', 'job_openings.id'))
            ->delete();
    }
};
