<?php

namespace App\Services\Parcel;

use App\Models\Franchise;
use App\Models\ParcelOrder;
use App\Models\ParcelProtectionClaim;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Parcel Stage 2 — protection claims (owner decision 2026-10-31). A protected parcel (the sender declared its value
 * and paid the protection fee) that was lost or damaged: the sender files a claim with photos within
 * parcel.protection_claim_days. An admin accepts (with the amount, at most the declared value) or declines; the
 * payout then follows the MANUAL MONEY ACTIONS approval model (CLAUDE.md), the same shape as
 * MarketplaceReturnService:
 *  - permission  parcel_claims.payout (Super Admin always), checked here, server-side; deciding is parcel_claims.manage;
 *  - scope       franchise holders act only on their franchise's claims; global/country/city holders are HQ;
 *  - limits      refund.parcel_claim.franchise_limit / hq_limit (rupees, null = that level cannot approve);
 *  - maker-checker  refund.parcel_claim.dual_approval_above (null = off);
 *  - escalation  refund.parcel_claim.escalate_after_hours (null = off), franchise -> HQ -> Super Admin;
 *  - reason required, row-locked, idempotent (unique wallet ref), never automatic.
 * Money: HQ pays the claim into the customer's wallet (the HQ ledger); franchise users approve, they never move funds.
 */
class ParcelProtectionClaimService
{
    public const MANAGE = 'parcel_claims.manage';

    public const PAYOUT = 'parcel_claims.payout';

    public const LEVEL_FRANCHISE = 1;

    public const LEVEL_HQ = 2;

    public const LEVEL_SUPER = 3;

    public const FRANCHISE_LIMIT_KEY = 'refund.parcel_claim.franchise_limit';

    public const HQ_LIMIT_KEY = 'refund.parcel_claim.hq_limit';

    public const DUAL_APPROVAL_KEY = 'refund.parcel_claim.dual_approval_above';

    public const ESCALATE_HOURS_KEY = 'refund.parcel_claim.escalate_after_hours';

    public const PHOTOS_MAX = 5;

    public function __construct(private AuthorizationService $authz, private WalletService $wallet)
    {
    }

    // ============================== customer ==============================

    /** Why this customer cannot claim on this order now, or null if they can. */
    public function cannotClaim(ParcelOrder $order, int $customerId): ?string
    {
        if ($order->customer_id !== $customerId) {
            return 'This is not your parcel.';
        }
        if (! $order->isProtected()) {
            return 'This parcel was not protected.';
        }
        if (! $order->picked_up_at || ! in_array($order->status, ['delivered', 'cancelled'], true)) {
            return 'A claim can be made once the parcel has been delivered, or the order closed after pickup.';
        }
        $days = (int) ParcelSettings::get('parcel.protection_claim_days', $this->scope($order));
        $closedAt = $order->delivered_at ?? $order->updated_at;
        if ($closedAt->copy()->addDays($days)->isPast()) {
            return "Claims must be made within {$days} days.";
        }
        if (ParcelProtectionClaim::where('parcel_order_id', $order->id)->where('status', '!=', ParcelProtectionClaim::DECLINED)->exists()) {
            return 'A claim is already open for this parcel.';
        }

        return null;
    }

    /** @param  list<UploadedFile>  $photos */
    public function file(ParcelOrder $order, User $customer, string $reason, string $description, float $amount, array $photos): ParcelProtectionClaim
    {
        if (($why = $this->cannotClaim($order, $customer->id)) !== null) {
            throw new \RuntimeException($why);
        }
        if (! array_key_exists($reason, ParcelProtectionClaim::REASONS)) {
            throw new \RuntimeException('Choose lost or damaged.');
        }
        if (trim($description) === '') {
            throw new \RuntimeException('Tell us what happened.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > (float) $order->declared_value) {
            throw new \RuntimeException('Claim more than 0 and at most the declared value ('.number_format((float) $order->declared_value, 2).').');
        }
        if ($reason === 'damaged' && $photos === []) {
            throw new \RuntimeException('Add at least one photo of the damage.');
        }
        if (count($photos) > self::PHOTOS_MAX) {
            throw new \RuntimeException('Add at most '.self::PHOTOS_MAX.' photos.');
        }

        $paths = [];
        try {
            foreach ($photos as $photo) {
                $paths[] = $photo->store("parcel-claims/{$order->id}", 'local');
            }

            $claim = ParcelProtectionClaim::create([
                'parcel_order_id' => $order->id, 'franchise_id' => $order->franchise_id, 'customer_id' => $customer->id,
                'reason' => $reason, 'description' => trim($description), 'photos' => $paths,
                'amount_claimed' => $amount, 'payout_amount' => $amount, 'status' => ParcelProtectionClaim::REQUESTED,
            ]);
        } catch (\Throwable $e) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
        ActivityLogger::logModel($customer, $claim, "Parcel protection claim filed (#{$claim->id})", ['order' => $order->code, 'reason' => $reason, 'amount' => $amount]);

        return $claim;
    }

    private function scope(ParcelOrder $order): array
    {
        $order->loadMissing('franchise');

        return array_filter(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id, 'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id]);
    }

    // ============================== who may act ==============================

    private function scopeFor(?int $franchiseId): array
    {
        if (! $franchiseId) {
            return [];
        }
        $franchise = Franchise::find($franchiseId);

        return array_filter(['franchise_id' => $franchiseId, 'city_id' => $franchise?->city_id, 'country_id' => $franchise?->country_id]);
    }

    /** 3 = Super Admin, 2 = HQ grant, 1 = franchise grant, null = no such permission over this franchise. */
    public function levelOf(User $user, ?int $franchiseId, string $permission = self::PAYOUT): ?int
    {
        if ($user->role === 'super_admin') {
            return self::LEVEL_SUPER;
        }
        $scope = $this->scopeFor($franchiseId);
        $best = null;
        foreach ($user->roleAssignments()->with('role.permissions')->get() as $assignment) {
            if (! $assignment->role->permissions->contains('slug', $permission)) {
                continue;
            }
            if (! $this->authz->scopeCovers($assignment->scope_type, $assignment->scope_id, $scope)) {
                continue;
            }
            $best = max($best ?? 0, $assignment->scope_type === 'franchise' ? self::LEVEL_FRANCHISE : self::LEVEL_HQ);
        }

        return $best;
    }

    public function canEnter(User $user): bool
    {
        return $this->authz->canAnywhere($user, self::MANAGE) || $this->authz->canAnywhere($user, self::PAYOUT);
    }

    /** Claims this user may see: Super Admin / global = all; franchise / city / country grants = their franchises. */
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'super_admin') {
            return $query;
        }
        $ids = [];
        foreach ($user->roleAssignments()->with('role.permissions')->get() as $assignment) {
            if (! $assignment->role->permissions->contains(fn ($p) => in_array($p->slug, [self::MANAGE, self::PAYOUT], true))) {
                continue;
            }
            switch ($assignment->scope_type) {
                case 'global':
                    return $query;
                case 'franchise':
                    $ids[] = (int) $assignment->scope_id;
                    break;
                case 'city':
                    $ids = array_merge($ids, Franchise::where('city_id', $assignment->scope_id)->pluck('id')->all());
                    break;
                case 'country':
                    $ids = array_merge($ids, Franchise::where('country_id', $assignment->scope_id)->pluck('id')->all());
                    break;
            }
        }

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('franchise_id', array_unique($ids));
    }

    private function rupees(string $key): ?float
    {
        $value = Setting::get($key);

        return ($value === null || trim((string) $value) === '') ? null : round((float) $value, 2);
    }

    public function limit(int $level): ?float
    {
        return match ($level) {
            self::LEVEL_SUPER => (float) PHP_INT_MAX,
            self::LEVEL_HQ => $this->rupees(self::HQ_LIMIT_KEY),
            self::LEVEL_FRANCHISE => $this->rupees(self::FRANCHISE_LIMIT_KEY),
            default => null,
        };
    }

    private function canCover(?int $level, ParcelProtectionClaim $c): bool
    {
        $limit = $level === null ? null : $this->limit($level);

        return $limit !== null && (float) $c->payout_amount <= $limit;
    }

    private function needsDualApproval(ParcelProtectionClaim $c): bool
    {
        $threshold = $this->rupees(self::DUAL_APPROVAL_KEY);

        return $threshold !== null && (float) $c->payout_amount > $threshold;
    }

    /** 'decide' | 'request' | 'approve' | 'retry' | null — UI only; every action re-checks. */
    public function availableAction(ParcelProtectionClaim $c, User $user): ?string
    {
        if ($c->status === ParcelProtectionClaim::REQUESTED) {
            return $this->levelOf($user, $c->franchise_id, self::MANAGE) !== null ? 'decide' : null;
        }
        $level = $this->levelOf($user, $c->franchise_id);
        if ($level === null) {
            return null;
        }

        return match ($c->payout_status) {
            ParcelProtectionClaim::PAYOUT_AWAITING_REQUEST => 'request',
            ParcelProtectionClaim::PAYOUT_AWAITING_APPROVAL => ($user->id !== $c->payout_requested_by_id && $this->canCover($level, $c)) ? 'approve' : null,
            ParcelProtectionClaim::PAYOUT_FAILED => $this->canCover($level, $c) ? 'retry' : null,
            default => null,
        };
    }

    // ============================== decide (no money moves) ==============================

    /** @return array{ok: bool, message: string} */
    public function decide(ParcelProtectionClaim $claim, User $admin, bool $accept, string $note, ?float $amount = null): array
    {
        $note = trim($note);

        return DB::transaction(function () use ($claim, $admin, $accept, $note, $amount) {
            $c = ParcelProtectionClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== ParcelProtectionClaim::REQUESTED) {
                return $this->no('This claim has already been decided.');
            }
            if ($this->levelOf($admin, $c->franchise_id, self::MANAGE) === null) {
                return $this->no('You are not permitted to decide this claim.');
            }
            if ($note === '') {
                return $this->no('A note is required.');
            }
            if ($accept && $amount !== null) {
                $cap = (float) ParcelOrder::whereKey($c->parcel_order_id)->value('declared_value');
                if ($amount <= 0 || $amount > $cap) {
                    return $this->no('The payout must be more than 0 and at most the declared value.');
                }
                $c->payout_amount = round($amount, 2);
            }

            $c->status = $accept ? ParcelProtectionClaim::ACCEPTED : ParcelProtectionClaim::DECLINED;
            $c->payout_status = $accept ? ParcelProtectionClaim::PAYOUT_AWAITING_REQUEST : null;
            $c->decided_by_id = $admin->id;
            $c->decision_note = $note;
            $c->decided_at = now();
            $c->save();
            ActivityLogger::logModel($admin, $c, 'Parcel protection claim '.($accept ? 'accepted' : 'declined')." (#{$c->id})", ['note' => $note, 'amount' => $c->payout_amount]);

            return ['ok' => true, 'message' => $accept ? 'Claim accepted. The payout is now waiting in the approval queue.' : 'Claim declined. The customer sees your note.'];
        });
    }

    // ============================== payout (approval model) ==============================

    /** @return array{ok: bool, message: string} */
    public function requestPayout(ParcelProtectionClaim $claim, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($claim, $actor, $reason) {
            $c = ParcelProtectionClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->payout_status !== ParcelProtectionClaim::PAYOUT_AWAITING_REQUEST) {
                return $this->no('This payout is not waiting for a request.');
            }
            $level = $this->levelOf($actor, $c->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to pay this claim.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            $c->payout_requested_by_id = $actor->id;
            $c->payout_request_reason = $reason;
            $c->payout_requested_at = now();
            ActivityLogger::logModel($actor, $c, "Parcel protection payout requested (#{$c->id})", ['amount' => $c->payout_amount, 'reason' => $reason, 'level' => $level]);

            if (! $this->needsDualApproval($c) && $this->canCover($level, $c)) {
                return $this->execute($c, $actor);
            }

            $c->payout_status = ParcelProtectionClaim::PAYOUT_AWAITING_APPROVAL;
            $c->save();

            return ['ok' => true, 'message' => 'Request recorded. It now needs approval from a different user with a sufficient limit.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function approvePayout(ParcelProtectionClaim $claim, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($claim, $actor, $reason) {
            $c = ParcelProtectionClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->payout_status !== ParcelProtectionClaim::PAYOUT_AWAITING_APPROVAL) {
                return $this->no('This payout is not awaiting approval.');
            }
            $level = $this->levelOf($actor, $c->franchise_id);
            if ($level === null) {
                return $this->no('You are not permitted to approve this payout.');
            }
            if ($actor->id === $c->payout_requested_by_id) {
                return $this->no('You cannot approve your own request. A different user must approve it.');
            }
            if (! $this->canCover($level, $c)) {
                return $this->no('Your approval limit does not cover this amount.');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }

            $c->payout_approved_by_id = $actor->id;
            $c->payout_approval_reason = $reason;
            $c->payout_approved_at = now();
            ActivityLogger::logModel($actor, $c, "Parcel protection payout approved (#{$c->id})", ['amount' => $c->payout_amount, 'reason' => $reason, 'requested_by_id' => $c->payout_requested_by_id]);

            return $this->execute($c, $actor);
        });
    }

    /** @return array{ok: bool, message: string} */
    public function rejectPayout(ParcelProtectionClaim $claim, User $actor, string $reason): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($claim, $actor, $reason) {
            $c = ParcelProtectionClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->payout_status !== ParcelProtectionClaim::PAYOUT_AWAITING_APPROVAL) {
                return $this->no('This payout is not awaiting approval.');
            }
            $level = $this->levelOf($actor, $c->franchise_id);
            if ($level === null || $actor->id === $c->payout_requested_by_id || ! $this->canCover($level, $c)) {
                return $this->no('You cannot reject this request (permission, own request, or above your limit).');
            }
            if ($reason === '') {
                return $this->no('A reason is required.');
            }
            ActivityLogger::logModel($actor, $c, "Parcel protection payout rejected (#{$c->id})", ['rejection_reason' => $reason, 'requested_by_id' => $c->payout_requested_by_id]);

            $c->payout_status = ParcelProtectionClaim::PAYOUT_AWAITING_REQUEST;
            $c->payout_requested_by_id = null;
            $c->payout_request_reason = null;
            $c->payout_requested_at = null;
            $c->payout_rejected_at = now();
            $c->escalation_level = 0;
            $c->last_escalated_at = null;
            $c->save();

            return ['ok' => true, 'message' => 'Request rejected. The payout is back awaiting a new request.'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function retry(ParcelProtectionClaim $claim, User $actor): array
    {
        return DB::transaction(function () use ($claim, $actor) {
            $c = ParcelProtectionClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($c->payout_status !== ParcelProtectionClaim::PAYOUT_FAILED) {
                return $this->no('Only a failed payout can be retried.');
            }
            if (! $this->canCover($this->levelOf($actor, $c->franchise_id), $c)) {
                return $this->no('You are not permitted to retry this payout, or it is above your limit.');
            }
            ActivityLogger::logModel($actor, $c, "Parcel protection payout retry (#{$c->id})");

            return $this->execute($c, $actor);
        });
    }

    /** Inside the caller's transaction with $c locked. A failure is recorded, not thrown, so that state commits. */
    private function execute(ParcelProtectionClaim $c, User $actor): array
    {
        $order = ParcelOrder::with('customer')->find($c->parcel_order_id);
        $amount = round((float) $c->payout_amount, 2);

        try {
            $this->wallet->credit(
                $order->customer,
                $amount,
                reason: "Parcel protection payout — parcel {$order->code}",
                ref: "parcel_order:{$order->id}:protection-claim-{$c->id}",
                actorId: $actor->id,
            );
        } catch (\Throwable $e) {
            $c->payout_status = ParcelProtectionClaim::PAYOUT_FAILED;
            $c->payout_failure_message = mb_substr($e->getMessage(), 0, 1000);
            $c->save();
            ActivityLogger::logModel($actor, $c, "Parcel protection payout FAILED (#{$c->id})", ['amount' => $amount, 'error' => $e->getMessage()]);

            return $this->no('The payout could not be processed. Nothing was paid; you can retry. Details are in the activity log.');
        }

        $c->payout_status = ParcelProtectionClaim::PAYOUT_DONE;
        $c->status = ParcelProtectionClaim::PAID;
        $c->paid_at = now();
        $c->payout_failure_message = null;
        $c->save();

        ActivityLogger::logModel($actor, $c, "Parcel protection claim paid (#{$c->id})", [
            'amount' => $amount, 'requested_by_id' => $c->payout_requested_by_id, 'approved_by_id' => $c->payout_approved_by_id,
        ]);

        return ['ok' => true, 'message' => '₹'.number_format($amount, 2).' credited to the customer\'s wallet.'];
    }

    private function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    // ============================== escalation ==============================

    public function escalateAfterHours(): ?int
    {
        $value = Setting::get(self::ESCALATE_HOURS_KEY);

        return ($value === null || trim((string) $value) === '' || (int) $value < 1) ? null : (int) $value;
    }

    public function handlerLevel(ParcelProtectionClaim $c): int
    {
        $franchiseLimit = $this->limit(self::LEVEL_FRANCHISE);
        $hqLimit = $this->limit(self::LEVEL_HQ);
        $amount = (float) $c->payout_amount;
        if ($c->franchise_id && $franchiseLimit !== null && $amount <= $franchiseLimit) {
            return self::LEVEL_FRANCHISE;
        }

        return ($hqLimit !== null && $amount <= $hqLimit) ? self::LEVEL_HQ : self::LEVEL_SUPER;
    }

    /** One level up per elapsed interval, once per level; audit-logged. @return int escalations recorded */
    public function escalateOverdue(): int
    {
        $hours = $this->escalateAfterHours();
        if ($hours === null) {
            return 0;
        }
        $raised = 0;
        ParcelProtectionClaim::whereIn('payout_status', ParcelProtectionClaim::PAYOUT_OPEN)->orderBy('id')->chunkById(200, function ($rows) use ($hours, &$raised) {
            foreach ($rows as $c) {
                $handler = $this->handlerLevel($c);
                $target = min(self::LEVEL_SUPER, $handler + intdiv((int) $c->payoutClockStartedAt()->diffInHours(now()), $hours));
                if ($target <= $handler || $target <= $c->escalation_level) {
                    continue;
                }
                $c->update(['escalation_level' => $target, 'last_escalated_at' => now()]);
                ActivityLogger::logModel(null, $c, "Parcel protection payout #{$c->id} escalated to level {$target}", ['hours' => $hours]);
                $raised++;
            }
        });

        return $raised;
    }
}
