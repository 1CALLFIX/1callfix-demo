<?php

namespace App\Services\Catalog;

use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Services\ActivityLogger;
use App\Services\Slug\SlugManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits one service category into several (owner 2026-11-22: "make them separate", for the old combined
 * "Appliance | AC Repair" and "Laptop| CCTV | IT Services" from the previous app).
 *
 * The existing row is KEPT as the first part (renamed, new clean slug, its old slug 301s to it through SlugManager), so
 * everything that points at its id keeps working. Every other part is a new category (same image, icon, colour and
 * on/off as the original) that takes its subcategories and their services, archived ones included. Every partner
 * who had the old category as a skill gets each new category too, so no one stops receiving jobs. Idempotent: run
 * again, it finds nothing to move.
 */
final class CategorySplitter
{
    /**
     * @param  string  $keepName  new name of the existing row
     * @param  array<string, list<string>>  $parts  new category name => subcategory slugs (within the original) it takes
     * @return array<string, int> category name => id, the kept row first
     */
    public function split(ServiceCategory $original, string $keepName, array $parts): array
    {
        return DB::transaction(function () use ($original, $keepName, $parts) {
            $ids = [$keepName => $original->id];
            $before = ['name' => $original->name, 'slug' => $original->slug];
            $offset = 0;

            foreach ($parts as $name => $subSlugs) {
                $offset++;
                $new = ServiceCategory::withTrashed()->where('module', $original->module)->where('name', $name)->first()
                    ?? $this->createLike($original, $name, $offset);
                $ids[$name] = $new->id;

                $subIds = ServiceSubcategory::withTrashed()->where('category_id', $original->id)->whereIn('slug', $subSlugs)->pluck('id');
                if ($subIds->isNotEmpty()) {
                    ServiceSubcategory::withTrashed()->whereIn('id', $subIds)->update(['category_id' => $new->id]);
                    Service::withTrashed()->whereIn('subcategory_id', $subIds)->update(['category_id' => $new->id]);
                    if (Schema::hasTable('service_cart_items') && Schema::hasColumn('service_cart_items', 'subcategory_id')) {
                        DB::table('service_cart_items')->whereIn('subcategory_id', $subIds)->update(['category_id' => $new->id]);
                    }
                }
            }

            // Partners who did the old combined work keep getting every part of it.
            $added = array_values(array_diff($ids, [$original->id]));
            Provider::query()->whereNotNull('skills')->get(['id', 'skills'])->each(function (Provider $p) use ($original, $added) {
                $skills = array_map('intval', (array) $p->skills);
                if (in_array((int) $original->id, $skills, true)) {
                    $merged = array_values(array_unique(array_merge($skills, $added)));
                    if ($merged !== $skills) {
                        $p->forceFill(['skills' => $merged])->save();
                    }
                }
            });

            if ($original->name !== $keepName) {
                $original->forceFill(['name' => $keepName])->save();
            }
            SlugManager::change($original, SlugManager::generate($original->refresh(), $keepName), null, true);

            ActivityLogger::logModel(null, $original, 'Service category split into '.implode(', ', array_keys($ids)), [
                'before' => $before, 'categories' => $ids,
            ]);

            return $ids;
        });
    }

    /** Renames a subcategory and its slug (the old slug keeps redirecting). */
    public function renameSubcategory(ServiceSubcategory $sub, string $name): void
    {
        if ($sub->name === $name) {
            return;
        }
        $before = $sub->name;
        $sub->forceFill(['name' => $name])->save();
        SlugManager::change($sub, SlugManager::generate($sub->refresh(), $name), null, true);
        ActivityLogger::logModel(null, $sub, 'Service subcategory renamed', ['before' => $before, 'after' => $name]);
    }

    /** Category order: each split part sits right after the category it came from; the rest keep their order. */
    public function renumber(string $module): void
    {
        ServiceCategory::withTrashed()->where('module', $module)->orderBy('sort_order')->orderBy('id')->get()
            ->each(fn (ServiceCategory $c, int $i) => $c->sort_order === ($i + 1) * 10 ? null : $c->forceFill(['sort_order' => ($i + 1) * 10])->save());
    }

    private function createLike(ServiceCategory $original, string $name, int $offset): ServiceCategory
    {
        $new = new ServiceCategory([
            'module' => $original->module,
            'name' => $name,
            'icon' => $original->icon,
            'color' => $original->color,
            'image' => $original->image,
            'description' => null,
            // Straight after the original (sort_order is renumbered in tens afterwards).
            'sort_order' => (int) $original->sort_order,
            'is_active' => (bool) $original->is_active,
        ]);
        $new->slug = SlugManager::generate($new, $name);
        $new->save();
        // Keeps parts in the order given when sort_order ties with the original (ordered by id after sort_order).
        ActivityLogger::logModel(null, $new, 'Service category created by a split', ['from' => $original->id, 'part' => $offset]);

        return $new;
    }
}
