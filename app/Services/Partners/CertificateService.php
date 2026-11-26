<?php

namespace App\Services\Partners;

use App\Models\Accommodation;
use App\Models\BusinessCertificate;
use App\Models\EventOrganiser;
use App\Models\Hall;
use App\Models\Store;
use App\Models\User;
use App\Support\InfoPages\ContactDetails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Owner 2026-11-26: a "Verified 1CallFix Partner" certificate for every business partner, to print and show at the
 * counter or on the wall, with a "Scan to order / book" sticker for the door and the counter.
 *
 *   - Shops and restaurants (Shop app), hotels (Hotel app), function halls (Rental app, one per hall) and event
 *     organisers (Events app). House and vehicle owners are people renting out their own things, not a place
 *     customers walk into, so they have none.
 *   - Only for a business 1CallFix approved and that is switched on. Business name, owner, kind and city, partner ID,
 *     verified since, valid till (one year), FSSAI for a restaurant. Never the owner's phone or home address.
 *   - The certificate's QR opens /verify/{token}, the same live check as the ID card (IdCardService): Active, Expired
 *     or Not active right now, so an old or copied certificate is useless. The sticker's QR opens the business's own
 *     page on 1CallFix, where customers order or book.
 */
class CertificateService
{
    public const VALID_MONTHS = 12;

    /** app => business_type */
    public const APPS = ['seller' => 'store', 'hotel' => 'hotel', 'venue' => 'hall', 'organiser' => 'organiser'];

    /**
     * kind => [label, colour (the app's theme colour), letter in the partner ID, what the sticker asks customers to do].
     */
    public const KINDS = [
        'shop' => ['Shop', '#059669', 'S', 'Scan to shop from us online'],
        'restaurant' => ['Restaurant', '#e11d48', 'F', 'Scan to order food online'],
        'hotel' => ['Hotel', '#7c3aed', 'H', 'Scan to book a room'],
        'hall' => ['Function hall', '#0d9488', 'V', 'Scan to book this hall'],
        'organiser' => ['Event organiser', '#db2777', 'E', 'Scan to see our events'],
    ];

    /** @return Collection<int, Store|Accommodation|Hall|EventOrganiser> the account's businesses in one app, oldest first */
    public function businesses(User $user, string $app): Collection
    {
        $class = $this->classFor(self::APPS[$app] ?? abort(404));

        return $class::with('franchise.city')
            ->whereHas('provider', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('id')->get();
    }

    /** One of the account's own businesses in that app, or null (another partner's: never). */
    public function own(User $user, string $app, int $id): Store|Accommodation|Hall|EventOrganiser|null
    {
        return $this->businesses($user, $app)->firstWhere('id', $id);
    }

    /** ready | draft (hall not sent yet) | pending (waiting for 1CallFix) | rejected (needs changes) | off (switched off) */
    public function state(Model $business): string
    {
        $approval = $business instanceof Hall ? (string) $business->status : (string) $business->approval_status;

        return match (true) {
            $approval === 'approved' => $business->is_active ? 'ready' : 'off',
            $approval === 'rejected' => 'rejected',
            $approval === 'draft' => 'draft',
            default => 'pending',
        };
    }

    /** The certificate, made the first time the business is ready. */
    public function certificateFor(User $user, Model $business): BusinessCertificate
    {
        return BusinessCertificate::firstOrCreate(
            ['business_type' => $this->typeOf($business), 'business_id' => $business->id],
            ['user_id' => $user->id, 'token' => $this->newToken(), 'issued_at' => now(), 'valid_until' => now()->addMonths(self::VALID_MONTHS)],
        );
    }

    /** A new year: a new QR and date. The old certificate's QR stops working at once. */
    public function renew(BusinessCertificate $certificate): BusinessCertificate
    {
        $certificate->update(['token' => $this->newToken(), 'issued_at' => now(), 'valid_until' => now()->addMonths(self::VALID_MONTHS)]);

        return $certificate->fresh();
    }

    public function businessOf(BusinessCertificate $certificate): Store|Accommodation|Hall|EventOrganiser|null
    {
        $class = $this->classFor($certificate->business_type);

        return $class ? $class::with(['franchise.city', 'provider.user'])->find($certificate->business_id) : null;
    }

    /** active | expired | not_active, checked now (the verify page and the certificate screen say the same). */
    public function status(BusinessCertificate $certificate, ?Model $business = null): string
    {
        $business ??= $this->businessOf($certificate);
        $user = $business?->provider?->user;
        $working = $business && $user && $user->status === 'active' && $this->state($business) === 'ready';

        if (! $working) {
            return 'not_active';
        }

        return $certificate->isExpired() ? 'expired' : 'active';
    }

    /** shop | restaurant | hotel | hall | organiser */
    public function kind(Model $business): string
    {
        return match (true) {
            $business instanceof Store => $business->isRestaurant() ? 'restaurant' : 'shop',
            $business instanceof Accommodation => 'hotel',
            $business instanceof Hall => 'hall',
            default => 'organiser',
        };
    }

    /** e.g. 1CF-NLR-S0012 (shop 12 in Nellore), F restaurant, H hotel, V function hall, E event organiser. */
    public function code(Model $business): string
    {
        $city = strtoupper((string) ($business->franchise?->code ?: 'IN'));

        return '1CF-'.$city.'-'.self::KINDS[$this->kind($business)][2].str_pad((string) $business->id, 4, '0', STR_PAD_LEFT);
    }

    /** The business's own page on 1CallFix (city-prefixed), where customers order or book; null without a city. */
    public function publicUrl(Model $business): ?string
    {
        $city = $business->franchise?->city?->slug;
        if (! $city) {
            return null;
        }

        return match ($this->kind($business)) {
            'shop' => route('customer.shop.store', [$city, $business->slug]),
            'restaurant' => route('customer.food.restaurant', [$city, $business->slug]),
            'hotel' => route('customer.hotels.show', [$city, $business->slug]),
            'hall' => route('customer.halls.show', [$city, $business->slug]),
            default => route('customer.events.home', $city),
        };
    }

    /**
     * Everything one certificate and its sticker show, for the screen, the PDF and the verify page.
     *
     * @return array{kind: string, label: string, colour: string, cta: string, name: string, owner: string, code: string, city: string, since: string, valid_until: string, year: string, fssai: ?string, qr: string, url: string, public_url: ?string, public_qr: ?string, company: string, office_phone: ?string}
     */
    public function data(BusinessCertificate $certificate, Model $business): array
    {
        $kind = $this->kind($business);
        [$label, $colour, , $cta] = self::KINDS[$kind];
        $url = route('partner.verify', $certificate->token);
        $publicUrl = $this->publicUrl($business);
        $qr = app(IdCardService::class);

        return [
            'kind' => $kind,
            'label' => $label,
            'colour' => $colour,
            'cta' => $cta,
            'name' => (string) $business->name,
            'owner' => (string) ($business->provider?->user?->name ?? ''),
            'code' => $this->code($business),
            'city' => (string) ($business->franchise?->city?->name ?? $business->franchise?->name ?? ''),
            'since' => ($business->approved_at ?? $certificate->issued_at)->format('j M Y'),
            'valid_until' => $certificate->valid_until->format('j M Y'),
            'year' => $certificate->issued_at->format('Y'),
            'fssai' => $kind === 'restaurant' && $business->fssai_number ? (string) $business->fssai_number : null,
            'qr' => $qr->qrDataUri($url),
            'url' => $url,
            'public_url' => $publicUrl,
            'public_qr' => $publicUrl ? $qr->qrDataUri($publicUrl) : null,
            'company' => ContactDetails::get('company') ?: '1CallFix',
            'office_phone' => ContactDetails::shown('phone'), // "+91 9014 609 609"
            'logo' => app(\App\Services\BrandingAssetService::class)->logoDataUri(), // the company logo (admin upload)
        ];
    }

    private function typeOf(Model $business): string
    {
        return match (true) {
            $business instanceof Store => 'store',
            $business instanceof Accommodation => 'hotel',
            $business instanceof Hall => 'hall',
            default => 'organiser',
        };
    }

    /** @return class-string<Model>|null */
    private function classFor(string $type): ?string
    {
        return ['store' => Store::class, 'hotel' => Accommodation::class, 'hall' => Hall::class, 'organiser' => EventOrganiser::class][$type] ?? null;
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
