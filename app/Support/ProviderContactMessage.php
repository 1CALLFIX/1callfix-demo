<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Setting;
use App\Services\ProviderCommercialRateResolver;
use App\Services\TimezoneResolver;

/**
 * Admin WhatsApp / Call fallback (owner approved 2026-10-16, L3_L8_PLAN.md §6): the pre-typed text staff send from
 * the business WhatsApp when no provider responds in the app. Click-to-chat only (wa.me) — no WhatsApp API, nothing
 * is sent by the server.
 *
 * Privacy rule: the OFFER never carries the customer's name, phone or full address (area / landmark / city only), so
 * a provider cannot go around the platform before the job is assigned to them.
 */
class ProviderContactMessage
{
    /** 10-digit Indian mobile → 91XXXXXXXXXX for wa.me / tel:, or null when the number is unusable. */
    public static function phoneDigits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        $last10 = substr($digits, -10);

        return strlen($last10) === 10 ? '91'.$last10 : null;
    }

    public static function whatsappUrl(?string $phone, string $text): ?string
    {
        $digits = self::phoneDigits($phone);

        return $digits ? 'https://wa.me/'.$digits.'?text='.rawurlencode($text) : null;
    }

    public static function telUrl(?string $phone): ?string
    {
        $digits = self::phoneDigits($phone);

        return $digits ? 'tel:+'.$digits : null;
    }

    public static function offer(Booking $booking, Provider $provider): string
    {
        $booking->loadMissing(['service', 'address', 'franchise']);
        $currency = Setting::get('locale.currency_symbol', '₹');
        $area = collect([$booking->address?->landmark, $booking->address?->city])->filter()->implode(', ');
        $when = $booking->scheduled_at
            ? app(TimezoneResolver::class)->format($booking->scheduled_at, $booking->franchise, 'D d M, h:i A')
            : 'As soon as possible';

        $lines = [
            "1CallFix job offer — {$booking->code}",
            'Service: '.($booking->service?->name ?? 'Service'),
            'Area: '.($area !== '' ? $area : 'shared after you accept'),
            "When: {$when}",
        ];

        $earning = self::estimatedEarning($booking, $provider);
        if ($earning !== null) {
            $lines[] = "Your approx. earning: {$currency}".number_format($earning, 2);
        }

        $lines[] = 'Reply YES to take this job. Customer details are shared once the job is assigned to you.';
        $lines[] = 'Your jobs: '.route('provider.jobs.index');

        return implode("\n", array_merge($lines, self::signature()));
    }

    public static function assigned(Booking $booking): string
    {
        $booking->loadMissing('service');

        $lines = [
            "1CallFix — job {$booking->code} is assigned to you.",
            'Service: '.($booking->service?->name ?? 'Service'),
            'Open the job for the customer details and start code steps: '.route('provider.jobs.show', $booking->id),
        ];

        return implode("\n", array_merge($lines, self::signature()));
    }

    /** Price minus HQ and franchise shares at today's admin-panel rates (plans are off, so no plan rate). */
    private static function estimatedEarning(Booking $booking, Provider $provider): ?float
    {
        $price = (float) ($booking->price_final ?? $booking->price_quoted);
        $franchise = $booking->franchise;

        if ($price <= 0 || ! $franchise) {
            return null;
        }

        try {
            $hqPercent = app(ProviderCommercialRateResolver::class)->resolve($franchise, $provider);
        } catch (\Throwable) {
            return null;
        }

        $franchisePercent = $franchise->commission_model === 'revenue_share' ? (float) ($franchise->commission_value ?? 0) : 0.0;

        return round($price - round($price * $hqPercent / 100, 2) - round($price * $franchisePercent / 100, 2), 2);
    }

    /** @return array<int, string> */
    private static function signature(): array
    {
        $number = trim((string) Setting::get('ops.whatsapp_business_number', ''));

        return $number !== '' ? ['', "— 1CallFix Operations, {$number}"] : [];
    }
}
