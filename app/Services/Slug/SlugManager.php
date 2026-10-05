<?php

namespace App\Services\Slug;

use App\Models\City;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\SlugRedirect;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Seo\ReservedSlugs;
use App\Support\SuperAdminGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * F3: the ONE place that generates, validates, changes and resolves public slugs.
 *
 * Namespaces ("scopes"):
 *  - catalog            categories + services share one /{city}/{slug} space. App-level uniqueness inside a
 *                       transaction; a category wins a lookup over a service. (A registry table can replace this later.)
 *  - city               /{city}
 *  - subcategory:{id}   unique within its category; no public page of its own yet, but edits are still redirected.
 *
 * Rules:
 *  - clean slugs; "-2", "-3" only on a real collision. Never random suffixes.
 *  - an item may re-take ITS OWN old slug; another item's old slug is rejected.
 *  - changing a slug leaves a permanent redirect from the old one (stored as an item reference, so chains never form).
 *  - editing needs `catalog.edit_slugs` (city slugs: Super Admin only) and is audit-logged.
 */
class SlugManager
{
    public const SCOPE_CATALOG = 'catalog';
    public const SCOPE_CITY = 'city';

    public const MAX_LENGTH = 100;

    /** target_type values stored in slug_redirects. */
    public const TYPE_CATEGORY = 'category';
    public const TYPE_SERVICE = 'service';
    public const TYPE_CITY = 'city';
    public const TYPE_SUBCATEGORY = 'subcategory';

    public static function scopeFor(Model $model): string
    {
        return match (true) {
            $model instanceof City => self::SCOPE_CITY,
            $model instanceof ServiceSubcategory => 'subcategory:'.$model->category_id,
            default => self::SCOPE_CATALOG,
        };
    }

    public static function typeFor(Model $model): string
    {
        return match (true) {
            $model instanceof City => self::TYPE_CITY,
            $model instanceof ServiceCategory => self::TYPE_CATEGORY,
            $model instanceof ServiceSubcategory => self::TYPE_SUBCATEGORY,
            $model instanceof Service => self::TYPE_SERVICE,
            default => throw new \InvalidArgumentException('No slug scope for '.$model::class),
        };
    }

    public static function normalize(?string $input): string
    {
        return Str::limit(Str::slug((string) $input), self::MAX_LENGTH, '');
    }

    /** A clean, free slug for a new or renamed item: "ac-repair", then "ac-repair-2", "-3"... */
    public static function generate(Model $model, ?string $name = null): string
    {
        $base = self::normalize($name ?? $model->name ?? '') ?: self::typeFor($model);

        $slug = $base;
        for ($n = 2; self::isTaken($model, $slug); $n++) {
            $slug = Str::limit($base, self::MAX_LENGTH - strlen((string) $n) - 1, '').'-'.$n;
        }

        return $slug;
    }

    /** Error message when `$slug` cannot be used by `$model`, null when it can. */
    public static function problem(Model $model, string $slug): ?string
    {
        if ($slug === '' || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return 'Use lowercase letters, numbers and single hyphens only (for example "ac-repair").';
        }
        if (strlen($slug) > self::MAX_LENGTH) {
            return 'The slug may be at most '.self::MAX_LENGTH.' characters.';
        }
        if (! $model instanceof ServiceSubcategory && ReservedSlugs::isReserved($slug)) {
            return '"'.$slug.'" is reserved because it is already used by another page of the website.';
        }
        if (self::liveOwnerExists($model, $slug)) {
            return 'Another item already uses this slug.';
        }
        if (self::redirectBelongsToOther($model, $slug)) {
            return 'This was the previous URL of a different item and keeps redirecting there.';
        }

        return null;
    }

    public static function isTaken(Model $model, string $slug): bool
    {
        return self::problem($model, $slug) !== null;
    }

    /** True when some other row currently owns the slug in this model's scope. */
    private static function liveOwnerExists(Model $model, string $slug): bool
    {
        $exceptSelf = fn ($query, string $type) => $query->when(
            $model->exists && self::typeFor($model) === $type,
            fn ($q) => $q->whereKeyNot($model->getKey()),
        );

        if ($model instanceof City) {
            return $exceptSelf(City::query()->where('slug', $slug), self::TYPE_CITY)->exists();
        }

        if ($model instanceof ServiceSubcategory) {
            return $exceptSelf(
                ServiceSubcategory::query()->where('category_id', $model->category_id)->where('slug', $slug),
                self::TYPE_SUBCATEGORY,
            )->exists();
        }

        return $exceptSelf(ServiceCategory::query()->where('slug', $slug), self::TYPE_CATEGORY)->exists()
            || $exceptSelf(Service::withTrashed()->where('slug', $slug), self::TYPE_SERVICE)->exists();
    }

    private static function redirectBelongsToOther(Model $model, string $slug): bool
    {
        $row = SlugRedirect::query()->where('scope', self::scopeFor($model))->where('old_slug', $slug)->first();

        if (! $row) {
            return false;
        }

        return ! ($model->exists && $row->target_type === self::typeFor($model) && (int) $row->target_id === (int) $model->getKey());
    }

    /** Who may edit this model's slug. City slugs: Super Admin only. Everything else: catalog.edit_slugs. */
    public static function canEdit(?User $user, Model $model): bool
    {
        if ($user === null) {
            return false;
        }

        return $model instanceof City
            ? SuperAdminGate::allows($user)
            // No scope passed on purpose: only a GLOBAL (HQ) assignment covers it, so a franchise-scoped
            // holder can never edit a slug (they edit page content only, see seo.edit_city_content).
            : $user->hasPermission('catalog.edit_slugs');
    }

    /**
     * Change an item's slug, leave a permanent redirect from the old one, audit-log it.
     * `$system = true` is for the cleanup command: it logs without a causer and skips the permission check.
     *
     * @return bool false when the slug was already current
     *
     * @throws ValidationException when the slug is invalid or taken
     */
    public static function change(Model $model, string $newSlug, ?User $actor, bool $system = false): bool
    {
        if (! $system) {
            abort_unless(self::canEdit($actor, $model), 403, $model instanceof City
                ? 'Only a Super Admin can change a city slug.'
                : 'You do not have permission to edit slugs.');
        }

        $newSlug = self::normalize($newSlug);

        return DB::transaction(function () use ($model, $newSlug, $actor) {
            $query = $model->newQuery();
            if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $query->withTrashed();
            }
            $fresh = $query->lockForUpdate()->findOrFail($model->getKey());

            $old = (string) $fresh->slug;
            if ($old === $newSlug) {
                return false;
            }

            if ($message = self::problem($fresh, $newSlug)) {
                throw ValidationException::withMessages(['slug' => $message]);
            }

            $scope = self::scopeFor($fresh);

            // Re-taking its own old slug: that redirect row is no longer needed.
            SlugRedirect::query()->where('scope', $scope)->where('old_slug', $newSlug)
                ->where('target_type', self::typeFor($fresh))->where('target_id', $fresh->getKey())->delete();

            if ($old !== '') {
                SlugRedirect::query()->updateOrCreate(
                    ['scope' => $scope, 'old_slug' => $old],
                    ['target_type' => self::typeFor($fresh), 'target_id' => $fresh->getKey()],
                );
            }

            $fresh->slug = $newSlug;
            $fresh->save();
            $model->slug = $newSlug;

            ActivityLogger::log($actor, self::typeFor($fresh).'_slug', (int) $fresh->getKey(), 'Slug changed', [
                'scope' => $scope,
                'old' => $old,
                'new' => $newSlug,
            ]);

            return true;
        });
    }

    /**
     * Make a (typically deactivated) duplicate's URL 301 to the item that is kept.
     * Used by catalog:clean-slugs. It only takes effect while the duplicate is inactive,
     * because a live, active slug always wins a lookup.
     */
    public static function redirectTo(Model $from, Model $to, ?User $actor = null): void
    {
        abort_if($from->is($to), 422, 'An item cannot redirect to itself.');
        abort_unless(self::scopeFor($from) === self::scopeFor($to), 422, 'Redirects only work inside one slug scope.');

        DB::transaction(function () use ($from, $to, $actor) {
            SlugRedirect::query()->updateOrCreate(
                ['scope' => self::scopeFor($from), 'old_slug' => (string) $from->slug],
                ['target_type' => self::typeFor($to), 'target_id' => $to->getKey()],
            );
            ActivityLogger::log($actor, self::typeFor($from).'_slug', (int) $from->getKey(), 'Redirect set to kept duplicate', [
                'slug' => $from->slug, 'to_type' => self::typeFor($to), 'to_id' => $to->getKey(),
            ]);
        });
    }

    /**
     * Resolve a /{city}/{slug} catalog slug.
     *
     *  - a live ACTIVE category wins, then a live ACTIVE service
     *  - not a live active slug -> its redirect row (an old slug, or a deactivated duplicate pointing at the kept item)
     *  - otherwise a live but inactive item is returned as-is; the page 404s it by its own visibility rule
     *
     * @return array{model: Model, via: string}|null via is "live" or "redirect"
     */
    public static function resolveCatalog(string $slug): ?array
    {
        $category = ServiceCategory::query()->where('slug', $slug)->orderByDesc('is_active')->orderBy('id')->first();
        $service = Service::query()->where('slug', $slug)->orderByDesc('is_active')->orderBy('id')->first();

        foreach ([$category, $service] as $live) {
            if ($live && $live->is_active) {
                return ['model' => $live, 'via' => 'live'];
            }
        }

        $redirect = SlugRedirect::query()->where('scope', self::SCOPE_CATALOG)->where('old_slug', $slug)->first();
        $target = $redirect ? self::targetOf($redirect) : null;
        if ($target) {
            return ['model' => $target, 'via' => 'redirect'];
        }

        $inactive = $category ?? $service;

        return $inactive ? ['model' => $inactive, 'via' => 'live'] : null;
    }

    /** @return array{model: Model, via: string}|null */
    public static function resolveCity(string $slug): ?array
    {
        $city = City::query()->where('slug', $slug)->first();
        if ($city) {
            return ['model' => $city, 'via' => 'live'];
        }

        $redirect = SlugRedirect::query()->where('scope', self::SCOPE_CITY)->where('old_slug', $slug)->first();
        $target = $redirect ? self::targetOf($redirect) : null;

        return $target ? ['model' => $target, 'via' => 'redirect'] : null;
    }

    private static function targetOf(SlugRedirect $row): ?Model
    {
        return match ($row->target_type) {
            self::TYPE_CATEGORY => ServiceCategory::find($row->target_id),
            self::TYPE_SERVICE => Service::find($row->target_id),
            self::TYPE_CITY => City::find($row->target_id),
            default => null,
        };
    }
}
