<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Services\ActivityLogger;
use App\Services\Slug\SlugManager;
use App\Support\Seo\QaRows;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F3: one-off clean-up of the catalog slugs that exist today.
 *
 *  - categories / subcategories carrying a random suffix ("appliance-ac-repair-Bs7r") get the clean slug
 *    ("appliance-ac-repair"; "-2", "-3" only on a real collision). The old slug stays alive as a permanent 301.
 *  - services sharing one slug (true duplicates): ONE keeps it (the lowest id unless --keep=ID), each other gets
 *    "-2", "-3"... and a redirect to the kept service, which takes effect as soon as that duplicate is deactivated.
 *    Nothing is ever deleted.
 *  - QA/demo rows ("[QA] ...") are left alone.
 *  - Anything it cannot decide safely (suffix that does not match the name, a service slug that is also a
 *    category slug, a category/service with an empty slug) is REPORTED, never changed.
 *
 * Dry run by default: prints exactly what --apply would do and changes nothing.
 */
class CatalogCleanSlugs extends Command
{
    protected $signature = 'catalog:clean-slugs
        {--apply : Actually make the changes (default is a dry run)}
        {--keep=* : Service id that keeps a shared slug (repeatable). Default: lowest id}';

    protected $description = 'Clean random-suffix catalog slugs and resolve duplicate service slugs (dry run unless --apply)';

    /** @var list<array<string, mixed>> */
    private array $plan = [];

    /** @var list<string> */
    private array $reports = [];

    /** @var list<string> "scope|slug" already promised to a row in this run */
    private array $planned = [];

    public function handle(): int
    {
        // The container reuses this command object within one process (tests, schedulers): start every run clean.
        $this->plan = $this->reports = $this->planned = [];

        $apply = (bool) $this->option('apply');
        $keep = array_map('intval', (array) $this->option('keep'));

        $this->planRandomSuffixes(ServiceCategory::query()->orderBy('id')->get(), 'category');
        $this->planRandomSuffixes(ServiceSubcategory::query()->orderBy('id')->get(), 'subcategory');
        $this->planDuplicateServices($keep);
        $this->planEmptyAndCrossType();

        $this->line($apply ? 'APPLY: changes will be written.' : 'DRY RUN: nothing is changed. Re-run with --apply to make these changes.');
        $this->newLine();

        if ($this->plan === []) {
            $this->info('No slug changes needed.');
        } else {
            $this->table(
                ['Type', 'ID', 'Name', 'Current slug', 'New slug', 'Redirect'],
                array_map(fn ($p) => [$p['type'], $p['id'], Str::limit($p['name'], 40), $p['from'], $p['to'], $p['redirect'] ?? 'old slug -> item'], $this->plan),
            );
        }

        foreach ($this->reports as $report) {
            $this->warn('REPORT (not changed): '.$report);
        }

        if (! $apply) {
            return self::SUCCESS;
        }

        foreach ($this->plan as $step) {
            DB::transaction(fn () => $this->applyStep($step));
        }

        $this->info('Done: '.count($this->plan).' change(s) applied.');

        return self::SUCCESS;
    }

    /** Random suffix: "<slug(name)>-<4 letters/digits>" where the prefix really is the name. */
    private function planRandomSuffixes($rows, string $type): void
    {
        foreach ($rows as $row) {
            if (QaRows::isQaName($row->name) || ! preg_match('/^(.+)-[A-Za-z0-9]{4}$/', (string) $row->slug, $m)) {
                continue;
            }

            $base = Str::slug((string) $row->name);

            if ($base === '' || $m[1] !== $base) {
                // Ends like a random suffix but does not start with the name (renamed since): a human decides.
                // A plain 4-letter word ("...-ac-repair-tips") has no digit or capital and is simply left alone.
                if (preg_match('/[0-9A-Z]/', substr((string) $row->slug, -4))) {
                    $this->reports[] = "{$type} #{$row->id} \"{$row->name}\" slug \"{$row->slug}\" ends like a random suffix but does not start with the name; edit it by hand if wanted.";
                }
                continue;
            }

            $new = SlugManager::generate($row, $row->name);
            // Two rows of one name in the same run must not be planned onto the same slug.
            $scope = SlugManager::scopeFor($row);
            for ($n = 2; in_array($scope.'|'.$new, $this->planned, true); $n++) {
                $new = $base.'-'.$n;
            }
            $this->planned[] = $scope.'|'.$new;
            if ($new !== $row->slug) {
                $this->plan[] = ['type' => $type, 'id' => $row->id, 'name' => $row->name, 'from' => $row->slug, 'to' => $new, 'action' => 'rename'];
            }
        }
    }

    /** @param list<int> $keep */
    private function planDuplicateServices(array $keep): void
    {
        $groups = Service::withTrashed()->orderBy('id')->get()->groupBy('slug')->filter(fn ($g, $slug) => $slug !== '' && $g->count() > 1);

        foreach ($groups as $slug => $services) {
            $services = $services->reject(fn ($s) => QaRows::isQaName($s->name));
            if ($services->count() < 2) {
                continue;
            }

            $keeper = $services->first(fn ($s) => in_array($s->id, $keep, true))
                ?? $services->first(fn ($s) => $s->is_active && $s->deleted_at === null)
                ?? $services->first();

            $taken = $services->pluck('slug')->all();
            foreach ($services->reject(fn ($s) => $s->id === $keeper->id) as $dup) {
                $n = 2;
                do {
                    $candidate = $slug.'-'.$n++;
                } while (in_array($candidate, array_column($this->plan, 'to'), true) || SlugManager::isTaken($dup, $candidate));

                $this->plan[] = [
                    'type' => 'service', 'id' => $dup->id, 'name' => $dup->name, 'from' => $slug, 'to' => $candidate,
                    'action' => 'dedupe', 'keeper' => $keeper->id,
                    'redirect' => "-> service #{$keeper->id} once #{$dup->id} is deactivated",
                ];
            }
            unset($taken);
        }
    }

    private function planEmptyAndCrossType(): void
    {
        foreach (Service::query()->where(fn ($q) => $q->whereNull('slug')->orWhere('slug', ''))->get() as $s) {
            $this->reports[] = "service #{$s->id} \"{$s->name}\" has an empty slug.";
        }
        foreach (ServiceCategory::query()->where(fn ($q) => $q->whereNull('slug')->orWhere('slug', ''))->get() as $c) {
            $this->reports[] = "category #{$c->id} \"{$c->name}\" has an empty slug.";
        }

        $categorySlugs = ServiceCategory::query()->pluck('slug')->all();
        foreach (Service::query()->whereIn('slug', $categorySlugs)->get() as $s) {
            $this->reports[] = "service #{$s->id} \"{$s->name}\" shares the slug \"{$s->slug}\" with a category (the category wins; rename the service by hand).";
        }
    }

    /** @param array<string, mixed> $step */
    private function applyStep(array $step): void
    {
        if ($step['type'] === 'category') {
            SlugManager::change(ServiceCategory::findOrFail($step['id']), $step['to'], null, true);

            return;
        }

        if ($step['type'] === 'subcategory') {
            SlugManager::change(ServiceSubcategory::findOrFail($step['id']), $step['to'], null, true);

            return;
        }

        // Duplicate service: the OLD address belongs to the kept service, so no redirect is made from it.
        $dup = Service::withTrashed()->findOrFail($step['id']);
        $old = $dup->slug;
        $dup->forceFill(['slug' => $step['to']])->save();
        ActivityLogger::log(null, 'service_slug', (int) $dup->id, 'Duplicate slug resolved', ['old' => $old, 'new' => $step['to'], 'keeper' => $step['keeper']]);

        SlugManager::redirectTo($dup->refresh(), Service::withTrashed()->findOrFail($step['keeper']));
    }
}
