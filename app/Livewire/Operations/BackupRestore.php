<?php

namespace App\Livewire\Operations;

use App\Jobs\RunBackupNowJob;
use App\Jobs\RunDataRestoreJob;
use App\Models\City;
use App\Models\DataRestore;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\Store;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\DeletedItems;
use App\Services\Backup\ScopedRestoreService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * REF 1CF-BACKUP-RESTORE-001 — System > Backup & Restore.
 *  - Backup: is the off-site backup arriving on time, "Back up now".
 *  - Deleted items: every archived franchise / city / zone / category / service / provider / store /
 *    banner, with Restore (no backup needed).
 *  - Restore one: pick a franchise, city, store or provider and a backup time, compare with now, bring
 *    back what is missing. Never overwrites newer data, never touches money (ScopedRestoreService).
 * Permission backups.restore (Super Admin always). The slow work runs on the queue (RunDataRestoreJob).
 */
class BackupRestore extends Component
{
    #[Url]
    public string $tab = 'backup';

    #[Url(as: 'type')]
    public string $deletedType = '';

    public string $deletedSearch = '';

    public string $scopeType = 'franchise';

    public string $scopeId = '';

    public string $backupId = '';

    public ?int $restoreId = null;

    public string $reason = '';

    public string $confirmText = '';

    public string $flash = '';

    public string $flashType = 'success';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('backups.restore'), 403, 'You do not have permission to use Backup & Restore.');

        $this->restoreId = DataRestore::query()->where('requested_by', auth()->id())
            ->whereIn('status', [DataRestore::PREPARING, DataRestore::READY, DataRestore::RESTORING])
            ->latest('id')->value('id');
    }

    public function updatedScopeType(): void
    {
        $this->scopeId = '';
    }

    public function backUpNow(): void
    {
        $this->authorizeAction();
        Cache::put(RunBackupNowJob::STATUS_KEY, ['state' => 'running', 'message' => 'Backing up…', 'at' => now()->toDateTimeString()], now()->addHour());
        RunBackupNowJob::dispatch(auth()->id());
        $this->say('success', 'Backup started. The status below updates when it is done.');
    }

    public function restoreDeleted(string $type, int $id): void
    {
        $this->authorizeAction();

        try {
            $name = app(DeletedItems::class)->restore($type, $id, auth()->user());
            $this->say('success', "{$name} restored.");
        } catch (Throwable $e) {
            $this->say('error', 'Could not restore: '.$e->getMessage());
        }
    }

    public function compare(): void
    {
        $this->authorizeAction();
        $this->validate([
            'scopeType' => 'required|in:'.implode(',', array_keys(ScopedRestoreService::TYPES)),
            'scopeId' => 'required|integer|min:1',
            'backupId' => 'required|string',
        ], [], ['scopeId' => 'item', 'backupId' => 'backup time']);

        if (DataRestore::query()->whereIn('status', [DataRestore::PREPARING, DataRestore::RESTORING])->exists()) {
            $this->say('error', 'Another restore is running. Wait for it to finish.');

            return;
        }

        try {
            $backup = app(BackupCatalog::class)->find($this->backupId);
        } catch (Throwable $e) {
            $this->say('error', $e->getMessage());

            return;
        }

        $restore = DataRestore::create([
            'scope_type' => $this->scopeType,
            'scope_id' => (int) $this->scopeId,
            'scope_label' => $this->liveLabel($this->scopeType, (int) $this->scopeId),
            'backup_id' => $backup['id'],
            'backup_at' => $backup['at'],
            'status' => DataRestore::PREPARING,
            'requested_by' => auth()->id(),
        ]);

        $this->restoreId = $restore->id;
        $this->reason = '';
        $this->confirmText = '';
        $this->flash = '';
        RunDataRestoreJob::dispatch($restore->id, 'compare', auth()->id());
    }

    public function restoreMissing(): void
    {
        $this->authorizeAction();
        $restore = $this->currentRestore();
        if (! $restore || $restore->status !== DataRestore::READY) {
            return;
        }

        $this->validate([
            'reason' => 'required|string|min:10|max:1000',
            'confirmText' => 'required|in:RESTORE',
        ], ['confirmText.in' => 'Type RESTORE in capitals to confirm.'], ['confirmText' => 'confirmation']);

        $restore->update(['status' => DataRestore::RESTORING, 'reason' => $this->reason, 'executed_by' => auth()->id(), 'error' => null]);
        RunDataRestoreJob::dispatch($restore->id, 'restore_missing', auth()->id());
    }

    public function restoreRow(string $table, int $rowId): void
    {
        $this->authorizeAction();
        $restore = $this->currentRestore();
        if (! $restore || $restore->status !== DataRestore::READY) {
            return;
        }

        $this->validate(['reason' => 'required|string|min:10|max:1000'], [], ['reason' => 'reason']);

        $restore->update(['status' => DataRestore::RESTORING, 'reason' => $this->reason, 'executed_by' => auth()->id(), 'error' => null]);
        RunDataRestoreJob::dispatch($restore->id, 'restore_row', auth()->id(), $table, $rowId);
    }

    public function startOver(): void
    {
        $this->restoreId = null;
        $this->reason = '';
        $this->confirmText = '';
        $this->flash = '';
    }

    private function authorizeAction(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('backups.restore'), 403);
    }

    private function currentRestore(): ?DataRestore
    {
        return $this->restoreId ? DataRestore::find($this->restoreId) : null;
    }

    private function say(string $type, string $message): void
    {
        $this->flashType = $type;
        $this->flash = $message;
    }

    private function liveLabel(string $type, int $id): ?string
    {
        return collect($this->itemOptions($type))->firstWhere('id', $id)['name'] ?? null;
    }

    /** Items of the chosen kind, archived ones included (marked), so a deleted one can still be chosen. */
    private function itemOptions(string $type): array
    {
        $query = match ($type) {
            'franchise' => Franchise::withTrashed()->orderBy('name'),
            'city' => City::withTrashed()->orderBy('name'),
            'store' => Store::withTrashed()->orderBy('name'),
            'provider' => Provider::withTrashed()->with('user')->latest('id'),
            default => null,
        };

        return $query ? $query->limit(1000)->get()->map(fn ($m) => [
            'id' => $m->id,
            'name' => ($m instanceof Provider ? ($m->user?->name ?? 'Provider #'.$m->id) : $m->name)
                .($m->deleted_at ? ' (deleted)' : ''),
        ])->all() : [];
    }

    public function render()
    {
        $catalog = app(BackupCatalog::class);
        $all = ['backups' => [], 'remote_error' => null];
        $status = ['last_at' => null, 'healthy' => false, 'copies' => 0, 'remote_error' => null];

        if ($this->tab === 'backup') {
            try {
                $all = $catalog->all();
                $status = $catalog->status();
            } catch (Throwable $e) {
                $status['remote_error'] = $e->getMessage();
            }
        }

        $restore = $this->currentRestore();

        return view('livewire.operations.backup-restore', [
            'status' => $status,
            'backupNow' => Cache::get(RunBackupNowJob::STATUS_KEY),
            'backups' => $all['backups'],
            'types' => ScopedRestoreService::TYPES,
            'items' => $this->tab === 'backup' ? $this->itemOptions($this->scopeType) : [],
            'restore' => $restore,
            'deletedTypes' => collect(DeletedItems::types())->map(fn ($t) => $t[1])->all(),
            'deleted' => $this->tab === 'deleted' ? app(DeletedItems::class)->list($this->deletedType, $this->deletedSearch) : collect(),
            'history' => DataRestore::query()->with(['requester', 'executor'])->latest('id')->limit(10)->get(),
        ])->layout('layouts.admin', ['title' => 'Backup & Restore']);
    }
}
