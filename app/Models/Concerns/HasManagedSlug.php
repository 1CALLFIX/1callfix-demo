<?php

namespace App\Models\Concerns;

use App\Services\Slug\SlugManager;

/**
 * F3: gives a model a clean, collision-safe slug on create (never a random suffix) and the editing entry point.
 * Cities, categories, subcategories and services use it. An explicit slug passed on create is kept only if valid.
 */
trait HasManagedSlug
{
    public static function bootHasManagedSlug(): void
    {
        static::creating(function ($model) {
            $given = SlugManager::normalize((string) ($model->slug ?? ''));

            $model->slug = ($given !== '' && SlugManager::problem($model, $given) === null)
                ? $given
                : SlugManager::generate($model);
        });
    }

    /** @see SlugManager::change() */
    public function changeSlug(string $slug, ?\App\Models\User $actor, bool $system = false): bool
    {
        return SlugManager::change($this, $slug, $actor, $system);
    }
}
