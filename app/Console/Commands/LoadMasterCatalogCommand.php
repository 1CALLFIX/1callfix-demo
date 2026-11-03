<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\MarketplaceCategory;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-03: loads the 1CallFix master product list into catalog_items, so a seller can "Add from list".
 * Safe to run again: an item already loaded is kept (its phase is refreshed), nothing is ever deleted, and an
 * existing shop category is never changed. A category or sub-category that does not exist yet is created —
 * switched ON when it has items for Launch, OFF for Later (Admin → Marketplace Categories turns them on).
 */
class LoadMasterCatalogCommand extends Command
{
    protected $signature = 'catalog:load-master {--file= : CSV with category,sub_category,product_name,phase} {--dry-run : Count only, change nothing}';

    protected $description = 'Load the 1CallFix master product list (database/data/master_catalog.csv) for sellers to add from';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/master_catalog.csv');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }

        $rows = $this->read($file);
        if ($rows === null) {
            return self::FAILURE;
        }
        $this->info(count($rows).' items in '.basename($file).'.');

        $dry = (bool) $this->option('dry-run');
        $counts = ['items_added' => 0, 'items_kept' => 0, 'categories_added' => 0];

        $work = function () use ($rows, $dry, &$counts) {
            $launchCategories = collect($rows)->where('phase', 'Launch')->pluck('category')->unique()->all();
            $launchSubs = collect($rows)->where('phase', 'Launch')->map(fn ($r) => $r['category'].'|'.$r['sub_category'])->unique()->all();
            $parents = [];
            $children = [];

            foreach ($rows as $r) {
                $parentKey = $r['category'];
                if (! array_key_exists($parentKey, $parents)) {
                    $parents[$parentKey] = $this->category($r['category'], null, false, in_array($r['category'], $launchCategories, true), $dry, $counts);
                }
                $childKey = $r['category'].'|'.$r['sub_category'];
                if (! array_key_exists($childKey, $children)) {
                    $children[$childKey] = $this->category($r['sub_category'], $parents[$parentKey], true, in_array($childKey, $launchSubs, true), $dry, $counts);
                }

                $existing = CatalogItem::where(['module' => 'commerce', 'category' => $r['category'], 'sub_category' => $r['sub_category'], 'name' => $r['name']])->first();
                if ($existing) {
                    $counts['items_kept']++;
                    if (! $dry) {
                        $existing->update(['phase' => $r['phase'], 'marketplace_category_id' => $existing->marketplace_category_id ?? $children[$childKey]?->id]);
                    }

                    continue;
                }
                $counts['items_added']++;
                if (! $dry) {
                    CatalogItem::create([
                        'module' => 'commerce', 'category' => $r['category'], 'sub_category' => $r['sub_category'], 'name' => $r['name'],
                        'phase' => $r['phase'], 'marketplace_category_id' => $children[$childKey]?->id, 'is_active' => true,
                    ]);
                }
            }
        };

        $dry ? $work() : DB::transaction($work);

        $this->table(['', 'count'], collect($counts)->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values()->all());
        if ($dry) {
            $this->warn('Dry run: nothing was changed.');
        } else {
            ActivityLogger::log(null, 'catalog_items', 0, 'Master product list loaded', $counts + ['file' => basename($file)]);
            $this->info('Done.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, array{category:string, sub_category:string, name:string, phase:string}>|null */
    private function read(string $file): ?array
    {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);
        foreach (['category', 'sub_category', 'product_name'] as $need) {
            if (! in_array($need, $header, true)) {
                $this->error("The file needs a '{$need}' column.");

                return null;
            }
        }
        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $r = array_combine($header, array_pad($data, count($header), ''));
            $row = [
                'category' => mb_substr(trim($r['category']), 0, 80),
                'sub_category' => mb_substr(trim($r['sub_category']), 0, 80),
                'name' => mb_substr(trim($r['product_name']), 0, 150),
                'phase' => strcasecmp(trim($r['phase'] ?? ''), 'Later') === 0 ? 'Later' : 'Launch',
            ];
            if ($row['category'] === '' || $row['sub_category'] === '' || $row['name'] === '') {
                $this->warn("Line {$line} skipped: category, sub_category and product_name are all needed.");

                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function category(string $name, ?MarketplaceCategory $parent, bool $isChild, bool $launch, bool $dry, array &$counts): ?MarketplaceCategory
    {
        if ($isChild && $parent === null) { // dry run under a category that would be new
            $counts['categories_added']++;

            return null;
        }
        $found = MarketplaceCategory::where('module', 'commerce')->where('name', $name)
            ->when($parent, fn ($q) => $q->where('parent_id', $parent->id), fn ($q) => $q->whereNull('parent_id'))->first();
        if ($found) {
            return $found; // never changed
        }
        $counts['categories_added']++;
        if ($dry) {
            return null;
        }

        return MarketplaceCategory::create([
            'module' => 'commerce', 'parent_id' => $parent?->id, 'name' => $name, 'is_active' => $launch,
            'position' => (int) MarketplaceCategory::where('module', 'commerce')->where('parent_id', $parent?->id)->max('position') + 1,
        ]);
    }
}
