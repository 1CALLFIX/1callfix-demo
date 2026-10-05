<?php

namespace App\Livewire\Concerns;

use App\Services\Slug\SlugManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * F3: the slug field of an admin edit modal. The field is only editable for users who hold
 * `catalog.edit_slugs` (city slugs: Super Admin only); everyone else sees the slug read-only.
 * The change itself goes through SlugManager::change(), which re-checks the permission server-side,
 * validates, leaves the permanent redirect and writes the audit log.
 */
trait EditsManagedSlug
{
    public string $editSlug = '';

    protected function loadEditSlug(Model $model): void
    {
        $this->editSlug = (string) $model->slug;
    }

    public function canEditSlug(Model $model): bool
    {
        return SlugManager::canEdit(auth()->user(), $model);
    }

    /**
     * Apply a changed slug before the rest of the edit is saved.
     *
     * @return bool false when the slug was rejected (the error is already on `editSlug`) - the caller stops
     */
    protected function applyEditSlug(Model $model): bool
    {
        $wanted = SlugManager::normalize($this->editSlug);

        if ($wanted === (string) $model->slug || ! $this->canEditSlug($model)) {
            return true;
        }

        try {
            SlugManager::change($model, $wanted, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('editSlug', $e->errors()['slug'][0] ?? 'This slug cannot be used.');

            return false;
        }

        $this->editSlug = $wanted;

        return true;
    }
}
