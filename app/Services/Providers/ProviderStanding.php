<?php

namespace App\Services\Providers;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\ProviderCommissionReceivable;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Support\EarningsSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Audit 2026-10-15 L1/L2/L3/L5/L6 — the ONE rule for "may this provider take new work right now?".
 *
 * Before this class the rule lived in four places that disagreed: dispatch offers ignored the wallet floor (L1),
 * cash-commission dues were ignored everywhere (L3), admin assign checked nothing (L5) and a suspended account still
 * received offers (L6). Dispatch (offers, scheduled offers, nearby list), acceptance, admin assign and the provider's
 * own readiness / pay-dues screens now all read this class.
 *
 * Settings (Super Admin, `wallet.` keys are SettingsAuditor-logged, scope cascade zone → franchise → city → country):
 *   - wallet.provider_min_balance_enabled  '1' on / '0' off. Unset = the pre-existing behaviour (on when the amount
 *                                          is above 0), so nothing changes until the owner flips it.
 *   - wallet.provider_min_balance_to_accept_jobs  the floor amount (existing key).
 *   - wallet.provider_floor_counts_dues    '1' = the floor compares balance − unpaid cash commission. Unset = off.
 *   - wallet.provider_max_cash_dues        most unpaid cash commission before new work stops. Unset/blank = no limit;
 *                                          '0' = any unpaid amount blocks.
 *
 * Money is never moved here; this only reads.
 */
class ProviderStanding
{
    public const SUSPENDED = 'suspended';

    public const INACTIVE = 'inactive';

    public const KYC = 'kyc_not_approved';

    public const BELOW_FLOOR = 'below_wallet_floor';

    public const DUES_OVER_LIMIT = 'cash_dues_over_limit';

    public const WRONG_ZONE = 'wrong_zone';

    public const NO_SKILL = 'no_skill';

    /** @return array<string, int> the provider's position in the settings cascade */
    public function scope(Provider $provider): array
    {
        $provider->loadMissing('franchise');

        return array_filter([
            'zone_id' => $provider->zone_id,
            'franchise_id' => $provider->franchise_id,
            'city_id' => $provider->franchise?->city_id,
            'country_id' => $provider->franchise?->country_id,
        ]);
    }

    /** The active wallet floor for this provider, or null when no floor applies. */
    public function floor(Provider $provider): ?float
    {
        $scope = $this->scope($provider);
        $switch = Setting::get('wallet.provider_min_balance_enabled', null, $scope);

        if ($switch !== null && $switch !== '' && (string) $switch !== '1') {
            return null;
        }

        $amount = (float) Setting::get('wallet.provider_min_balance_to_accept_jobs', '0', $scope);

        return $amount > 0 ? $amount : null;
    }

    public function floorCountsDues(Provider $provider): bool
    {
        return EarningsSettings::on('wallet.provider_floor_counts_dues', $this->scope($provider));
    }

    /** Most unpaid cash commission allowed, or null = no limit. */
    public function maxDues(Provider $provider): ?float
    {
        return EarningsSettings::number('wallet.provider_max_cash_dues', $this->scope($provider));
    }

    public function dues(Provider $provider): float
    {
        return round((float) ProviderCommissionReceivable::outstanding()
            ->where('provider_id', $provider->id)
            ->sum(DB::raw('amount_owed - amount_settled')), 2);
    }

    public function balance(Provider $provider): float
    {
        return round((float) (Wallet::where('user_id', $provider->user_id)->value('balance') ?? 0), 2);
    }

    /**
     * Everything the screens need, computed once.
     *
     * @return array{balance: float, dues: float, floor: ?float, floor_counts_dues: bool, max_dues: ?float,
     *               effective_balance: float, blockers: array<int, array{code: string, message: string}>,
     *               pay_to_restore: float}
     */
    public function snapshot(Provider $provider, ?float $balance = null, ?float $dues = null): array
    {
        $provider->loadMissing('user');
        $balance ??= $this->balance($provider);
        $dues ??= $this->dues($provider);
        $floor = $this->floor($provider);
        $countsDues = $this->floorCountsDues($provider);
        $maxDues = $this->maxDues($provider);
        $effective = round($countsDues ? $balance - $dues : $balance, 2);

        $blockers = [];

        // Some screens load the user with a column list (id, name, phone); read status from the row when it is missing.
        $status = $provider->user && array_key_exists('status', $provider->user->getAttributes())
            ? $provider->user->status
            : User::whereKey($provider->user_id)->value('status');

        if ($status === 'suspended') {
            $blockers[] = ['code' => self::SUSPENDED, 'message' => 'The account is suspended.'];
        }
        if (! $provider->is_active) {
            $blockers[] = ['code' => self::INACTIVE, 'message' => 'The provider is marked inactive.'];
        }
        if ($provider->kyc_status !== 'approved') {
            $blockers[] = ['code' => self::KYC, 'message' => 'KYC is not approved yet.'];
        }
        if ($maxDues !== null && $dues > $maxDues) {
            $blockers[] = ['code' => self::DUES_OVER_LIMIT, 'message' => 'Unpaid cash commission ₹'.number_format($dues, 2)
                .' is above the limit of ₹'.number_format($maxDues, 2).'.'];
        }
        if ($floor !== null && $effective < $floor) {
            $blockers[] = ['code' => self::BELOW_FLOOR, 'message' => 'Wallet balance ₹'.number_format($effective, 2)
                .($countsDues && $dues > 0 ? ' (after unpaid commission)' : '')
                .' is below the minimum of ₹'.number_format($floor, 2).'.'];
        }

        return [
            'balance' => $balance,
            'dues' => $dues,
            'floor' => $floor,
            'floor_counts_dues' => $countsDues,
            'max_dues' => $maxDues,
            'effective_balance' => $effective,
            'blockers' => $blockers,
            'pay_to_restore' => $this->payToRestore($balance, $dues, $floor),
        ];
    }

    /**
     * What the provider must pay so that, after the payment is credited and the dues sweep runs, every rupee of
     * cash commission is cleared and the wallet still holds the floor: max(0, dues + floor − balance).
     */
    public function payToRestore(float $balance, float $dues, ?float $floor): float
    {
        return max(0.0, round($dues + ($floor ?? 0) - $balance, 2));
    }

    /** @return array<int, array{code: string, message: string}> */
    public function blockers(Provider $provider): array
    {
        return $this->snapshot($provider)['blockers'];
    }

    public function canTakeNewWork(Provider $provider): bool
    {
        return $this->blockers($provider) === [];
    }

    /** Throws the first reason, worded for the provider. */
    public function assertCanTakeNewWork(Provider $provider): void
    {
        $blockers = $this->blockers($provider);

        if ($blockers !== []) {
            $reason = $blockers[0];
            $hint = in_array($reason['code'], [self::BELOW_FLOOR, self::DUES_OVER_LIMIT], true)
                ? ' Pay your dues from Payments → Wallet & dues to start taking jobs again.'
                : '';

            throw new \RuntimeException('You cannot accept new jobs right now. '.$reason['message'].$hint);
        }
    }

    /**
     * Admin assign (L5): standing + the booking's zone and skill. Online / location are deliberately NOT required —
     * an admin may hand a job to a provider reached by phone or WhatsApp.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function blockersForBooking(Provider $provider, Booking $booking): array
    {
        $blockers = $this->blockers($provider);
        $booking->loadMissing('service');

        if ((int) $provider->zone_id !== (int) $booking->zone_id) {
            $blockers[] = ['code' => self::WRONG_ZONE, 'message' => 'Works in a different zone.'];
        }

        $categoryId = $booking->service?->category_id;
        if ($categoryId && ! in_array($categoryId, $provider->skills ?? [], false)) {
            $blockers[] = ['code' => self::NO_SKILL, 'message' => 'Does not offer this service category.'];
        }

        return $blockers;
    }

    /**
     * Admin assign / reassign gate (L5, owner decision 2026-10-16): staff pick from eligible providers; assigning an
     * ineligible one needs a written reason, which is logged with the reasons it overrode.
     *
     * @return string the note to append to the booking history ('' when the provider was eligible)
     *
     * @throws \RuntimeException when the provider is ineligible and no reason was given
     */
    public function authorizeAdminAssign(Provider $provider, Booking $booking, ?string $overrideReason, ?User $admin): string
    {
        $blockers = $this->blockersForBooking($provider, $booking);

        if ($blockers === []) {
            return '';
        }

        $reasons = implode(' ', array_column($blockers, 'message'));
        $overrideReason = trim((string) $overrideReason);

        if ($overrideReason === '') {
            throw new \RuntimeException("This provider is not eligible for this job: {$reasons} To assign anyway, give a reason.");
        }

        ActivityLogger::log($admin, Booking::class, $booking->id, 'Admin assigned an ineligible provider (override)', [
            'provider_id' => $provider->id,
            'blockers' => array_column($blockers, 'code'),
            'reason' => $overrideReason,
        ]);

        return "Override — provider not eligible ({$reasons}) Reason: {$overrideReason}";
    }

    /**
     * Batch form for dispatch: keeps only providers with no blockers, using one wallet query and one dues query for
     * the whole candidate list.
     *
     * @param  Collection<int, Provider>  $providers
     * @return Collection<int, Provider>
     */
    public function filterCanTakeNewWork(Collection $providers): Collection
    {
        if ($providers->isEmpty()) {
            return $providers;
        }

        $providers->loadMissing(['user', 'franchise']);

        $balances = Wallet::whereIn('user_id', $providers->pluck('user_id')->filter())->pluck('balance', 'user_id');
        $dues = ProviderCommissionReceivable::outstanding()
            ->whereIn('provider_id', $providers->pluck('id'))
            ->groupBy('provider_id')
            ->selectRaw('provider_id, SUM(amount_owed - amount_settled) as due')
            ->pluck('due', 'provider_id');

        return $providers->filter(fn (Provider $p) => $this->snapshot(
            $p,
            round((float) ($balances[$p->user_id] ?? 0), 2),
            round((float) ($dues[$p->id] ?? 0), 2),
        )['blockers'] === [])->values();
    }
}
