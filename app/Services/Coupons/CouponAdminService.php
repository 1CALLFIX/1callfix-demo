<?php

namespace App\Services\Coupons;

use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every coupon create / update / status / target change goes through here and
 * writes an old -> new diff to the append-only activity_log (design §9). The
 * Super Admin screens (C2) call this; nothing else mutates coupon rules.
 *
 * Existing bookings are unaffected by any edit: they read their frozen
 * coupon_snapshot, never the live coupon.
 */
class CouponAdminService
{
    private const AUDITED = [
        'franchise_id', 'code', 'name', 'description', 'status', 'module', 'discount_type', 'value',
        'min_order_value', 'max_discount', 'usage_limit', 'per_user_limit', 'total_budget', 'daily_budget', 'funding_mode', 'campaign_tag',
        'stackable_with_flash', 'valid_from', 'valid_until',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{target_type: string, target_id?: ?int, operator?: string, params?: ?array}>|null  $targets  null = leave targets untouched
     */
    public function save(User $actor, array $data, ?array $targets = null, ?Coupon $coupon = null): Coupon
    {
        $this->assertValid($data, $coupon);
        $this->assertScope($actor, $data, $targets, $coupon);

        return DB::transaction(function () use ($actor, $data, $targets, $coupon) {
            $creating = $coupon === null;
            $coupon ??= new Coupon(['created_by' => $actor->id]);

            $before = $creating ? [] : $coupon->only(self::AUDITED);

            $coupon->fill($data);
            $coupon->updated_by = $actor->id;
            $coupon->is_active = $coupon->status === 'active';
            $coupon->save();

            $changes = [];
            foreach (self::AUDITED as $field) {
                $new = $coupon->{$field};
                $old = $before[$field] ?? null;
                if ($creating ? $new !== null : (string) $old !== (string) $new) {
                    $changes[$field] = [$old, $new];
                }
            }

            if ($targets !== null) {
                $oldTargets = $coupon->targets()->get()->map->only(['target_type', 'target_id', 'operator', 'params'])->all();
                $coupon->targets()->delete();
                foreach ($targets as $t) {
                    CouponTarget::create([
                        'coupon_id' => $coupon->id,
                        'target_type' => $t['target_type'],
                        'target_id' => $t['target_id'] ?? null,
                        'operator' => $t['operator'] ?? 'include',
                        'params' => $t['params'] ?? null,
                    ]);
                }
                if ($oldTargets !== $targets) {
                    $changes['targets'] = [$oldTargets, $targets];
                }
            }

            ActivityLogger::logModel($actor, $coupon, $creating ? 'coupon.created' : 'coupon.updated', ['changes' => $changes]);

            return $coupon;
        });
    }

    /** Pause / resume / archive style transitions, with a mandatory reason. */
    public function setStatus(User $actor, Coupon $coupon, string $status, string $reason): Coupon
    {
        if (! in_array($status, Coupon::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown coupon status.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return DB::transaction(function () use ($actor, $coupon, $status, $reason) {
            $locked = Coupon::lockForUpdate()->findOrFail($coupon->id);
            $old = $locked->status;

            $locked->status = $status;
            $locked->is_active = $status === 'active';
            $locked->updated_by = $actor->id;
            $locked->save();

            ActivityLogger::logModel($actor, $locked, 'coupon.status_changed', [
                'changes' => ['status' => [$old, $status]],
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    private function assertValid(array $data, ?Coupon $coupon): void
    {
        $errors = [];

        if (isset($data['discount_type']) && ! in_array($data['discount_type'], ['flat', 'percent'], true)) {
            $errors['discount_type'] = 'Discount type must be flat or percent.';
        }
        if (isset($data['value']) && (float) $data['value'] <= 0) {
            $errors['value'] = 'Discount value must be greater than zero.';
        }
        if (($data['discount_type'] ?? $coupon?->discount_type) === 'percent' && isset($data['value']) && (float) $data['value'] > 100) {
            $errors['value'] = 'A percentage cannot exceed 100.';
        }
        // Only HQ funding exists until the C4 fund ledger; franchise/split/external cannot be selected.
        if (isset($data['funding_mode']) && $data['funding_mode'] !== 'hq') {
            $errors['funding_mode'] = 'Only HQ funding is available.';
        }
        // Null = no limit, 0 = invalid, positive = valid (hardening §K) — enforced here, not only in the form.
        foreach (['usage_limit', 'total_budget', 'daily_budget'] as $cap) {
            if (isset($data[$cap]) && (float) $data[$cap] <= 0) {
                $errors[$cap] = 'A limit must be greater than zero (leave blank for no limit).';
            }
        }
        if (isset($data['total_budget'], $data['daily_budget']) && (float) $data['daily_budget'] > (float) $data['total_budget']) {
            $errors['daily_budget'] = 'The daily cap cannot exceed the total budget.';
        }
        if (isset($data['status']) && ! in_array($data['status'], Coupon::STATUSES, true)) {
            $errors['status'] = 'Unknown coupon status.';
        }
        // The legacy default of 1 is not silently inherited: the owner must choose a limit.
        if (! $coupon && ! array_key_exists('per_user_limit', $data)) {
            $errors['per_user_limit'] = 'Choose how many times one customer can use this coupon.';
        }
        if (isset($data['code'])) {
            $taken = Coupon::withTrashed()->whereRaw('LOWER(code) = ?', [mb_strtolower(trim((string) $data['code']))])
                ->when($coupon, fn ($q) => $q->where('id', '!=', $coupon->id))->exists();
            if ($taken) {
                $errors['code'] = 'This code is already in use.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Hardening §E: blank targeting never means "everywhere". The resulting coupon must carry an explicit scope
     * (a franchise owner, a city/zone/franchise/customer include, or the explicit global marker), and choosing
     * the global marker is an approval-level act (coupons.approve; Super Admin always holds it).
     *
     * @param  array<int, array<string, mixed>>|null  $targets  null = existing targets stay
     */
    private function assertScope(User $actor, array $data, ?array $targets, ?Coupon $coupon): void
    {
        $effective = $targets !== null
            ? collect($targets)->map(fn (array $t) => new CouponTarget(['target_type' => $t['target_type'], 'operator' => $t['operator'] ?? 'include', 'params' => $t['params'] ?? null]))
            : ($coupon?->targets()->get() ?? collect());

        $owner = new Coupon(['franchise_id' => array_key_exists('franchise_id', $data) ? $data['franchise_id'] : $coupon?->franchise_id]);
        $matcher = app(TargetMatcher::class);

        if (! $matcher->hasExplicitScope($owner, $effective)) {
            throw ValidationException::withMessages(['targets' => 'Choose where this coupon applies: pick a city, or tick "Global / all eligible scope".']);
        }

        // D1: a new-customers-only coupon must carry the explicit Global marker; empty or city-only targeting is not enough.
        if ($matcher->isNewCustomerOnly($effective)) {
            if (! $matcher->isGlobal($effective)) {
                throw ValidationException::withMessages(['targets' => 'A new-customers-only coupon needs the explicit "Global / all eligible scope" choice.']);
            }
            $newlyNewOnly = $targets !== null && ! ($coupon && $matcher->isNewCustomerOnly($coupon->targets()->get()));
            if ($newlyNewOnly && ! $actor->hasPermission('coupons.approve')) {
                throw ValidationException::withMessages(['targets' => 'A new-customers-only coupon needs the coupons.approve permission.']);
            }
        }

        $newlyGlobal = $targets !== null && $matcher->isGlobal($effective)
            && ! ($coupon && $matcher->isGlobal($coupon->targets()->get()));
        if ($newlyGlobal && ! $actor->hasPermission('coupons.approve')) {
            throw ValidationException::withMessages(['targets' => 'Global scope needs the coupons.approve permission.']);
        }
    }

    /**
     * Archive = soft delete, with a mandatory reason. The code stays reserved (uniqueness checks include trashed rows),
     * usage history is kept, and existing bookings are untouched (they read their frozen coupon_snapshot). Lookups skip
     * trashed coupons, so an archived code can no longer be redeemed.
     */
    public function archive(User $actor, Coupon $coupon, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        DB::transaction(function () use ($actor, $coupon, $reason) {
            $locked = Coupon::lockForUpdate()->findOrFail($coupon->id);
            $old = $locked->status;
            $locked->status = 'paused';
            $locked->is_active = false;
            $locked->updated_by = $actor->id;
            $locked->save();
            $locked->delete();

            ActivityLogger::logModel($actor, $locked, 'coupon.archived', [
                'changes' => ['status' => [$old, 'archived']],
                'reason' => $reason,
            ]);
        });
    }
}
