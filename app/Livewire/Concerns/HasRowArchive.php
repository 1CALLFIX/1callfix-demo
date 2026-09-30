<?php

namespace App\Livewire\Concerns;

use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * REF 1CF-ADMIN-ROWACTIONS-001 — list-row Delete (soft, reversible), Restore
 * and Super-Admin-only permanent delete, shared by the admin list screens.
 *
 * Deleting a row only archives it (deleted_at); it disappears from the normal
 * tabs and shows under the screen's "Archived" tab, where it can be restored.
 * Permanent deletion is Super Admin only, only for an already-archived row, and
 * refuses when other records still reference it. Every step is written to the
 * append-only activity log.
 *
 * The using component supplies archiveModel(), canArchiveRow() and (optionally)
 * archiveLabel()/archiveWarning(), and renders <x-ui.archive-bars /> +
 * <x-ui.row-actions /> in its view.
 */
trait HasRowArchive
{
    public ?int $confirmingArchiveId = null;

    public ?int $confirmingForceDeleteId = null;

    public string $rowFlash = '';

    public string $rowFlashType = 'success';

    /** @return class-string<Model> a model that uses SoftDeletes */
    abstract protected function archiveModel(): string;

    /** May the current admin archive / restore this row? */
    abstract protected function canArchiveRow(Model $row): bool;

    protected function archiveLabel(Model $row): string
    {
        return $row->name ?? $row->code ?? ('#'.$row->getKey());
    }

    /** Shown in the confirm bar (warn, don't block — archiving is reversible). */
    protected function archiveWarning(Model $row): ?string
    {
        return null;
    }

    /**
     * View data for <x-ui.archive-bars>: pass as `'archiveBars' => $this->archiveBars()`
     * from render(). Kept protected so none of this is callable from the browser.
     *
     * @return array{flash: string, flashType: string, archive: ?array{label: string, warning: ?string}, force: ?array{label: string}}
     */
    protected function archiveBars(): array
    {
        $archive = null;
        if ($this->confirmingArchiveId && ($row = ($this->archiveModel())::withTrashed()->find($this->confirmingArchiveId))) {
            $archive = ['label' => $this->archiveLabel($row), 'warning' => $this->archiveWarning($row)];
        }

        $force = null;
        if ($this->confirmingForceDeleteId && ($row = ($this->archiveModel())::withTrashed()->find($this->confirmingForceDeleteId))) {
            $force = ['label' => $this->archiveLabel($row)];
        }

        return ['flash' => $this->rowFlash, 'flashType' => $this->rowFlashType, 'archive' => $archive, 'force' => $force];
    }

    protected function isSuperAdminUser(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    private function findRow(int $id): Model
    {
        return ($this->archiveModel())::withTrashed()->findOrFail($id);
    }

    private function rowFlash(string $type, string $message): void
    {
        $this->rowFlashType = $type;
        $this->rowFlash = $message;
    }

    public function askArchive(int $id): void
    {
        $row = $this->findRow($id);
        abort_unless($this->canArchiveRow($row), 403);

        $this->confirmingForceDeleteId = null;
        $this->confirmingArchiveId = $id;
    }

    public function cancelArchive(): void
    {
        $this->confirmingArchiveId = null;
        $this->confirmingForceDeleteId = null;
    }

    public function confirmArchive(): void
    {
        if (! $this->confirmingArchiveId) {
            return;
        }

        $row = $this->findRow($this->confirmingArchiveId);
        $this->confirmingArchiveId = null;

        if (! $this->canArchiveRow($row)) {
            $this->rowFlash('error', 'You do not have permission to delete this record.');

            return;
        }

        $label = $this->archiveLabel($row);
        $row->delete();
        ActivityLogger::logModel(auth()->user(), $row, 'archived', ['label' => $label]);
        $this->rowFlash('success', "{$label} deleted. You can restore it from the Archived tab.");
        $this->resetPage();
    }

    public function restoreRow(int $id): void
    {
        $row = $this->findRow($id);

        if (! $this->canArchiveRow($row)) {
            $this->rowFlash('error', 'You do not have permission to restore this record.');

            return;
        }

        $row->restore();
        ActivityLogger::logModel(auth()->user(), $row, 'restored', ['label' => $this->archiveLabel($row)]);
        $this->rowFlash('success', $this->archiveLabel($row).' restored.');
    }

    public function askForceDelete(int $id): void
    {
        abort_unless($this->isSuperAdminUser(), 403);

        $row = $this->findRow($id);
        if (! $row->trashed()) {
            $this->rowFlash('error', 'Delete the record first (it moves to Archived), then it can be removed permanently.');

            return;
        }

        $this->confirmingArchiveId = null;
        $this->confirmingForceDeleteId = $id;
    }

    public function confirmForceDelete(): void
    {
        if (! $this->confirmingForceDeleteId) {
            return;
        }

        $id = $this->confirmingForceDeleteId;
        $this->confirmingForceDeleteId = null;

        if (! $this->isSuperAdminUser()) {
            $this->rowFlash('error', 'Only a Super Admin can delete permanently.');

            return;
        }

        $row = $this->findRow($id);
        $label = $this->archiveLabel($row);

        try {
            $row->forceDelete();
        } catch (QueryException) {
            $this->rowFlash('error', "{$label} is still referenced by other records (bookings, payments, history) and cannot be removed permanently. It stays in Archived.");

            return;
        }

        ActivityLogger::log(auth()->user(), $this->archiveModel(), $id, 'permanently deleted', ['label' => $label]);
        $this->rowFlash('success', "{$label} permanently deleted.");
        $this->resetPage();
    }
}
