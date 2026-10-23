<?php

namespace App\Services\Backup;

use App\Models\ActivityLog;
use App\Models\Banner;
use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\Store;
use App\Models\User;
use App\Models\Zone;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * REF 1CF-BACKUP-RESTORE-001 (Layer 1) — Backup & Restore > Deleted items: every archived (soft-deleted)
 * franchise, city, zone, category, service, provider, store and banner in one list, with Restore.
 * No backup is needed: deleting these only sets deleted_at.
 */
class DeletedItems
{
    /** type => [model, label, parent description] */
    public static function types(): array
    {
        return [
            'franchise' => [Franchise::class, 'Franchise', fn (Franchise $m) => $m->city?->name],
            'country' => [Country::class, 'Country', fn () => null],
            'city' => [City::class, 'City', fn (City $m) => $m->country?->name],
            'zone' => [Zone::class, 'Zone', fn (Zone $m) => $m->franchise?->name],
            'category' => [ServiceCategory::class, 'Category', fn () => null],
            'subcategory' => [ServiceSubcategory::class, 'Subcategory', fn (ServiceSubcategory $m) => $m->category?->name],
            'service' => [Service::class, 'Service', fn (Service $m) => $m->subcategory?->name ?? $m->category?->name],
            'provider' => [Provider::class, 'Provider', fn () => null],
            'store' => [Store::class, 'Store / restaurant', fn () => null],
            'banner' => [Banner::class, 'Banner', fn () => null],
        ];
    }

    /** @return Collection<int, array{type: string, type_label: string, id: int, name: string, parent: ?string, deleted_at: mixed, deleted_by: ?string}> */
    public function list(string $type = '', string $search = ''): Collection
    {
        $rows = collect();

        foreach (self::types() as $key => [$model, $typeLabel, $parent]) {
            if ($type !== '' && $type !== $key) {
                continue;
            }

            $model::onlyTrashed()->latest('deleted_at')->limit(200)->get()
                ->each(function (Model $m) use ($rows, $key, $typeLabel, $parent) {
                    $rows->push([
                        'type' => $key,
                        'type_label' => $typeLabel,
                        'id' => $m->getKey(),
                        'name' => $this->name($m),
                        'parent' => $parent($m),
                        'deleted_at' => $m->deleted_at,
                        'deleted_by' => $this->deletedBy($m),
                    ]);
                });
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['name'].' '.$r['parent']), $needle));
        }

        return $rows->sortByDesc('deleted_at')->values();
    }

    public function restore(string $type, int $id, User $actor): string
    {
        [$model] = self::types()[$type] ?? throw new RuntimeException('Unknown type.');
        $row = $model::onlyTrashed()->findOrFail($id);

        $row->restore();
        $name = $this->name($row);
        ActivityLogger::logModel($actor, $row, 'restored', ['label' => $name, 'from' => 'Backup & Restore > Deleted items']);

        return $name;
    }

    private function name(Model $m): string
    {
        if ($m instanceof Provider) {
            return $m->user?->name ?? ('Provider #'.$m->getKey());
        }

        return (string) ($m->name ?? $m->title ?? ('#'.$m->getKey()));
    }

    private function deletedBy(Model $m): ?string
    {
        $log = ActivityLog::query()
            ->where('subject_type', get_class($m))->where('subject_id', $m->getKey())
            ->whereIn('description', ['archived', 'deleted'])
            ->latest('id')->with('causer')->first();

        return $log?->causer?->name;
    }
}
