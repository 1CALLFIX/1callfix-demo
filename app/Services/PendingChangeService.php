<?php

namespace App\Services;

use App\Models\PendingChange;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Owner rule 2026-11-03 (after comparing Amazon, Flipkart, Swiggy, Zomato) — what a seller may change at once and
 * what waits for an admin, for every item on the platform (shop product, food, any item) and every shop:
 *  - at once: price, discount, stock, on/off, limit per order, choices' price/stock; shop logo, description, fees;
 *  - a NEW item waits for its first approval (hidden until then; the seller Products page does that);
 *  - an item's name, photos, description, category and a shop's address, map pin, GSTIN, PAN: the item / shop
 *    stays live with the OLD details; the new ones wait here; approve = they replace the old ones, reject = nothing
 *    changes. One waiting change per item / shop: a newer one replaces it.
 *  - the shop name is the admin's only; the shop mobile number changes after an OTP to the new number.
 */
class PendingChangeService
{
    /** The fields of each kind of record that wait for approval. */
    public const REVIEWED = [
        Product::class => ['name', 'description', 'images', 'marketplace_category_id'],
        Store::class => ['address_line', 'lat', 'lng', 'gstin', 'pan', 'fssai_number'],
    ];

    /**
     * Split the fields a seller saved into [apply now, wait for approval]. Only fields that really change go to
     * approval; unchanged ones are dropped from both.
     */
    public function split(Model $model, array $fields): array
    {
        $reviewed = self::REVIEWED[$model::class] ?? [];
        $now = [];
        $wait = [];
        foreach ($fields as $field => $value) {
            if (! in_array($field, $reviewed, true)) {
                $now[$field] = $value;

                continue;
            }
            if (! $this->same($model->getAttribute($field), $value)) {
                $wait[$field] = $value;
            }
        }
        // The map pin is one thing: both halves go together.
        if (isset($wait['lat']) || isset($wait['lng'])) {
            $wait['lat'] = $fields['lat'] ?? $model->lat;
            $wait['lng'] = $fields['lng'] ?? $model->lng;
        }

        return [$now, $wait];
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return array_values((array) ($a ?? [])) === array_values((array) ($b ?? []));
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0000001;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    public function pendingFor(Model $model): ?PendingChange
    {
        return PendingChange::pending()->where('changeable_type', $model->getMorphClass())->where('changeable_id', $model->getKey())->latest('id')->first();
    }

    /** Ask for a change. A change already waiting for the same item / shop is replaced (its new photos removed). */
    public function request(Model $model, array $changes, User $by, ?int $storeId): PendingChange
    {
        return DB::transaction(function () use ($model, $changes, $by, $storeId) {
            $previous = PendingChange::pending()->where('changeable_type', $model->getMorphClass())
                ->where('changeable_id', $model->getKey())->lockForUpdate()->get();
            foreach ($previous as $old) {
                $old->update(['status' => PendingChange::REPLACED]);
                $this->deleteUnusedPhotos($old, array_merge($model->images ?? [], $changes['images'] ?? []));
            }

            $original = [];
            foreach (array_keys($changes) as $field) {
                $original[$field] = $model->getAttribute($field);
            }
            $change = PendingChange::create([
                'changeable_type' => $model->getMorphClass(), 'changeable_id' => $model->getKey(), 'store_id' => $storeId,
                'requested_by' => $by->id, 'new_values' => $changes, 'old_values' => $original, 'status' => PendingChange::PENDING,
            ]);
            ActivityLogger::logModel($by, $model, 'Change sent for approval', ['pending_change_id' => $change->id, 'fields' => array_keys($changes)]);

            return $change;
        });
    }

    /** The seller went back to the live details: a change still waiting is dropped (its new photos removed). */
    public function withdraw(Model $model, User $by): void
    {
        DB::transaction(function () use ($model, $by) {
            $waiting = PendingChange::pending()->where('changeable_type', $model->getMorphClass())
                ->where('changeable_id', $model->getKey())->lockForUpdate()->get();
            foreach ($waiting as $old) {
                $old->update(['status' => PendingChange::REPLACED]);
                $this->deleteUnusedPhotos($old, $model->images ?? []);
                ActivityLogger::logModel($by, $model, 'Change withdrawn by the seller', ['pending_change_id' => $old->id]);
            }
        });
    }

    /** The new details replace the old ones. Idempotent: a change already decided is refused. */
    public function approve(PendingChange $change, User $admin): void
    {
        DB::transaction(function () use ($change, $admin) {
            $locked = PendingChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PendingChange::PENDING) {
                throw new RuntimeException('This change was already decided.');
            }
            $model = $locked->changeable;
            if (! $model) {
                throw new RuntimeException('The item or shop no longer exists.');
            }
            $oldPhotos = $model->images ?? [];
            $model->update($locked->new_values);
            if (array_key_exists('images', $locked->new_values)) {
                foreach (array_diff($oldPhotos, $locked->new_values['images'] ?? []) as $gone) {
                    $this->deletePhoto($gone);
                }
            }
            $locked->update(['status' => PendingChange::APPROVED, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            ActivityLogger::logModel($admin, $model, 'Approved change', ['pending_change_id' => $locked->id, 'changes' => $locked->new_values, 'before' => $locked->old_values]);
        });
    }

    /** Nothing changes on the item / shop; the photos uploaded for this change are removed. */
    public function reject(PendingChange $change, User $admin, string $note): void
    {
        $note = trim($note);
        if (mb_strlen($note) < 3) {
            throw new RuntimeException('Write a short reason for the seller.');
        }
        DB::transaction(function () use ($change, $admin, $note) {
            $locked = PendingChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PendingChange::PENDING) {
                throw new RuntimeException('This change was already decided.');
            }
            $locked->update(['status' => PendingChange::REJECTED, 'review_note' => mb_substr($note, 0, 500), 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $this->deleteUnusedPhotos($locked, $locked->changeable?->images ?? []);
            if ($locked->changeable) {
                ActivityLogger::logModel($admin, $locked->changeable, 'Rejected change', ['pending_change_id' => $locked->id, 'note' => $note]);
            }
        });
    }

    /** Photos uploaded for a change that are not in use anywhere else. */
    private function deleteUnusedPhotos(PendingChange $change, array $inUse): void
    {
        foreach (array_diff($change->new_values['images'] ?? [], $inUse) as $path) {
            $this->deletePhoto($path);
        }
    }

    private function deletePhoto(?string $path): void
    {
        if ($path && ! str_contains($path, '://')) {
            Storage::disk('public')->delete($path);
        }
    }
}
