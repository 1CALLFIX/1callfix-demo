<?php

namespace App\Services\Partners;

use App\Models\ServiceCategory;
use App\Models\FieldWorker;
use App\Models\FieldWorkerDocument;
use App\Models\PartnerIdCard;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\User;
use App\Services\Parcel\ParcelSettings;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Owner 2026-11-26: a 1CallFix ID card for the people who go to customers — service partners (Partner app) and
 * riders / drivers (Rider app). On the phone and as a PDF to print.
 *
 *   - Only after KYC approval, and only with a face photo the franchise approved (KYC document `id_card_photo`).
 *   - Name, photo, partner ID, work, city, valid till, QR. Never the home address or personal phone (a card is
 *     handed to strangers): city only, and customers reach the partner through the app.
 *   - The QR opens /verify/{token}: a live check, Active or Not active right now. Suspended, KYC withdrawn, expired
 *     or replaced (new token) all fail the scan, so an old or copied card is useless.
 *   - Valid one year; then the partner gets a new card (new token) from the same screen.
 */
class IdCardService
{
    public const PHOTO = 'id_card_photo';

    public const VALID_MONTHS = 12;

    public const TYPES = ['provider', 'rider'];

    /** Owner 2026-11-26: printed on the back of the card ("Blood Group: O +ve"). Stored => printed. */
    public const BLOOD_GROUPS = [
        'A+' => 'A +ve', 'A-' => 'A −ve', 'B+' => 'B +ve', 'B-' => 'B −ve',
        'AB+' => 'AB +ve', 'AB-' => 'AB −ve', 'O+' => 'O +ve', 'O-' => 'O −ve',
    ];

    /** Who the emergency number belongs to: tapped, not typed. */
    public const RELATIONS = ['Father', 'Mother', 'Wife', 'Husband', 'Son', 'Daughter', 'Brother', 'Sister', 'Friend'];

    /** Blood group and at least one emergency number are needed before the card is shown and printed. */
    public function detailsComplete(PartnerIdCard $card): bool
    {
        return isset(self::BLOOD_GROUPS[(string) $card->blood_group]) && ! empty($card->emergency_contacts);
    }

    /**
     * @param  list<array{relation: string, phone: string}>  $contacts  already validated (10-digit Indian mobile)
     */
    public function saveDetails(PartnerIdCard $card, string $bloodGroup, array $contacts): void
    {
        $card->update([
            'blood_group' => $bloodGroup,
            'emergency_contacts' => array_values(array_map(fn ($c) => ['relation' => $c['relation'], 'phone' => $c['phone']], $contacts)),
        ]);
    }

    /** 9876543210 → "98765 43210" */
    public static function phoneText(string $digits): string
    {
        return strlen($digits) === 10 ? substr($digits, 0, 5).' '.substr($digits, 5) : $digits;
    }

    /** The partner record behind one app's card for this account, or null when the account has no such role. */
    public function holder(User $user, string $type): Provider|FieldWorker|null
    {
        if ($type === 'provider') {
            return $user->providerProfile()->with('franchise.city')->first();
        }
        $rider = $user->fieldWorkerProfile()->with('franchise.city')->first();

        return $rider && $rider->hasCapability('parcel_rider') ? $rider : null;
    }

    /**
     * What the partner's ID card screen shows: not_field (no customer visits: a hotel or shop account), not_approved,
     * no_photo, photo_pending, photo_rejected, or ready.
     */
    public function state(Provider|FieldWorker $holder): string
    {
        if ($holder instanceof Provider && empty($holder->skills)) {
            return 'not_field';
        }
        if ($holder->kyc_status !== 'approved') {
            return 'not_approved';
        }

        return match ($this->photo($holder)?->status) {
            'approved' => 'ready',
            'pending' => 'photo_pending',
            'rejected' => 'photo_rejected',
            default => 'no_photo',
        };
    }

    public function photo(Provider|FieldWorker $holder): ProviderDocument|FieldWorkerDocument|null
    {
        return $holder->currentDocuments()->where('type', self::PHOTO)->latest('id')->first();
    }

    /** The card, made the first time it is ready. */
    public function cardFor(User $user, Provider|FieldWorker $holder): PartnerIdCard
    {
        return PartnerIdCard::firstOrCreate(
            ['holder_type' => $this->typeOf($holder), 'holder_id' => $holder->id],
            ['user_id' => $user->id, 'token' => $this->newToken(), 'issued_at' => now(), 'valid_until' => now()->addMonths(self::VALID_MONTHS)],
        );
    }

    /** Lost card or a new year: a new QR and date. The old card's QR stops working at once. */
    public function renew(PartnerIdCard $card): PartnerIdCard
    {
        $card->update(['token' => $this->newToken(), 'issued_at' => now(), 'valid_until' => now()->addMonths(self::VALID_MONTHS)]);

        return $card->fresh();
    }

    public function holderOf(PartnerIdCard $card): Provider|FieldWorker|null
    {
        return ($card->holder_type === 'rider' ? FieldWorker::query() : Provider::query())
            ->with(['franchise.city', 'user'])->find($card->holder_id);
    }

    /** active | expired | not_active, checked now (the verify page and the card screen say the same). */
    public function status(PartnerIdCard $card, Provider|FieldWorker|null $holder = null): string
    {
        $holder ??= $this->holderOf($card);
        $user = $holder?->user;
        $working = $holder && $user
            && $user->status === 'active'
            && $holder->is_active
            && $holder->kyc_status === 'approved'
            && ($holder instanceof Provider || $holder->hasCapability('parcel_rider'));

        if (! $working) {
            return 'not_active';
        }

        return $card->isExpired() ? 'expired' : 'active';
    }

    /** e.g. 1CF-NLR-P0075 (service partner 75 in Nellore) or 1CF-NLR-R0012 (rider 12). */
    public function code(Provider|FieldWorker $holder): string
    {
        $city = strtoupper((string) ($holder->franchise?->code ?: 'IN'));

        return '1CF-'.$city.'-'.($holder instanceof FieldWorker ? 'R' : 'P').str_pad((string) $holder->id, 4, '0', STR_PAD_LEFT);
    }

    /** What the partner does, in plain words: "AC Repair · Plumbing", "Delivery Rider", "Mini truck driver". */
    public function work(Provider|FieldWorker $holder): string
    {
        if ($holder instanceof FieldWorker) {
            $vehicle = $holder->vehicle_type ?: 'two_wheeler';

            return $vehicle === 'two_wheeler'
                ? 'Delivery Rider'
                : (ParcelSettings::VEHICLES[$vehicle]['label'] ?? 'Goods vehicle').' driver';
        }
        $names = ServiceCategory::whereIn('id', array_map('intval', (array) $holder->skills))->orderBy('name')->pluck('name');
        $text = $names->take(2)->implode(' · ');

        return $names->count() > 2 ? $text.' +'.($names->count() - 2) : ($text ?: 'Service Partner');
    }

    public function city(Provider|FieldWorker $holder): string
    {
        return (string) ($holder->franchise?->city?->name ?? $holder->franchise?->name ?? '');
    }

    /**
     * Everything one card shows, for the card screen, the PDF and the verify page. Blood group, emergency numbers and
     * the "property of" line only with $private (the partner's own screen and PDF), never on the public verify page.
     *
     * @return array{app: string, name: string, code: string, work: string, city: string, valid_until: string, photo: ?string, qr: string, url: string, blood_group?: ?string, emergency?: list<array{relation: string, phone: string}>, company?: string, office_phone?: ?string}
     */
    public function data(PartnerIdCard $card, Provider|FieldWorker $holder, bool $private = false): array
    {
        $url = route('partner.verify', $card->token);
        $extra = ! $private ? [] : [
            'blood_group' => self::BLOOD_GROUPS[(string) $card->blood_group] ?? null,
            'emergency' => array_map(fn ($c) => ['relation' => (string) ($c['relation'] ?? ''), 'phone' => self::phoneText((string) ($c['phone'] ?? ''))], (array) $card->emergency_contacts),
            // The Contact page settings (App\Support\InfoPages\ContactDetails): registered name and office number.
            'company' => \App\Support\InfoPages\ContactDetails::get('company') ?: '1CallFix',
            'office_phone' => \App\Support\InfoPages\ContactDetails::shown('phone'), // "+91 9014 609 609"
        ];

        return $extra + [
            'app' => $holder instanceof FieldWorker ? 'rider' : 'partner',
            'name' => (string) ($holder->user?->name ?? ''),
            'code' => $this->code($holder),
            'work' => $this->work($holder),
            'city' => $this->city($holder),
            'valid_until' => $card->valid_until->format('j M Y'),
            'photo' => $this->photoDataUri($holder),
            'qr' => $this->qrDataUri($url),
            'url' => $url,
        ];
    }

    /**
     * The approved photo, centre-cropped to a passport shape (4:5, 240×300 — a phone photo can be 10 MB and the PDF
     * maker cannot crop), as a data URI. Cached per document.
     */
    public function photoDataUri(Provider|FieldWorker $holder): ?string
    {
        $doc = $this->photo($holder);
        if (! $doc || $doc->status !== 'approved' || ! $doc->disk_path || ! str_starts_with((string) $doc->mime_type, 'image/')) {
            return null;
        }

        return Cache::remember('id-card-photo:'.$doc::class.':'.$doc->id, now()->addDay(), function () use ($doc) {
            if (! Storage::disk('local')->exists($doc->disk_path)) {
                return null;
            }
            $raw = Storage::disk('local')->get($doc->disk_path);
            $image = @imagecreatefromstring($raw);
            if (! $image) {
                return null;
            }
            $w = imagesx($image);
            $h = imagesy($image);
            // Largest 4:5 box in the middle (a little above centre for a portrait: faces sit high).
            $cropW = (int) min($w, round($h * 4 / 5));
            $cropH = (int) min($h, round($cropW * 5 / 4));
            $x = (int) round(($w - $cropW) / 2);
            $y = (int) max(0, round(($h - $cropH) / 3));
            $out = imagecreatetruecolor(240, 300);
            imagecopyresampled($out, $image, 0, 0, $x, $y, 240, 300, $cropW, $cropH);
            ob_start();
            imagejpeg($out, null, 85);

            return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
        });
    }

    public function qrDataUri(string $url): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'scale' => 8,
            'quietzoneSize' => 1,
            'outputBase64' => true,
        ])))->render($url);
    }

    private function typeOf(Provider|FieldWorker $holder): string
    {
        return $holder instanceof FieldWorker ? 'rider' : 'provider';
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
