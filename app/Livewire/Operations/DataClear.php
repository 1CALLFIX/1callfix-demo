<?php

namespace App\Livewire\Operations;

use App\Services\Operations\DataClearCatalog;
use App\Services\Operations\DataClearService;
use Livewire\Component;

/**
 * Operations "Clear Data" tool — the admin screen for DataClearService.
 * Mirrors Modules\Manage's checklist-of-toggles UI pattern, per the
 * approved design ("Present tables/modules as an independently-selectable
 * checklist"). This component owns UI state only (selection, the current
 * confirmation phrase, flash messages) — every actual safety decision
 * (environment gate, never-clearable enforcement, backup verification,
 * phrase comparison) lives in DataClearService, so this screen cannot
 * accidentally become a second, divergent copy of that logic.
 *
 * Refuses to even RENDER in production (404, not 403 — see mount()'s own
 * comment) on top of DataClearService::run()'s own environment gate, so a
 * production admin panel structurally has no path to this screen's
 * "Clear" button at all, not just a disabled one.
 */
class DataClear extends Component
{
    /** @var array<int,string> */
    public array $selectedModules = [];

    public string $confirmationPhrase = '';

    public string $typedConfirmation = '';

    public string $flashType = '';

    public string $flashMessage = '';

    public ?string $lastOperationId = null;

    public function mount(): void
    {
        // Belt-and-suspenders with DataClearService::run()'s own
        // environment check: that gate protects the ACTION, this protects
        // the SCREEN itself. 404, not 403 — a 403 confirms the screen
        // exists but is forbidden; a production admin panel should not be
        // able to discover this tool exists at all, let alone that it's
        // merely "forbidden right now".
        abort_if(app()->environment('production'), 404);

        abort_unless(auth()->user()->hasPermissionAnywhere('operations.data_clear'), 403, 'You do not have permission to use this tool.');
    }

    public function updatedSelectedModules(): void
    {
        $this->regeneratePhrase();
    }

    private function regeneratePhrase(): void
    {
        $this->typedConfirmation = '';
        $this->confirmationPhrase = empty($this->selectedModules)
            ? ''
            : app(DataClearService::class)->generateConfirmationPhrase($this->selectedModules);
    }

    /** @return array<string,int> table => row count, for the running total shown before confirmation. */
    public function getAffectedRowCountsProperty(): array
    {
        return empty($this->selectedModules)
            ? []
            : app(DataClearService::class)->affectedRowCounts($this->selectedModules);
    }

    public function getTotalAffectedRowsProperty(): int
    {
        return array_sum($this->affectedRowCounts);
    }

    public function execute(DataClearService $service): void
    {
        $this->flashType = '';
        $this->flashMessage = '';

        if (! auth()->user()->hasPermissionAnywhere('operations.data_clear')) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to use this tool.';

            return;
        }

        $result = $service->run(
            auth()->user(),
            $this->selectedModules,
            $this->typedConfirmation,
            $this->confirmationPhrase,
            request()->ip() ?? 'unknown'
        );

        if ($result->refused) {
            $this->flashType = 'error';
            $this->flashMessage = $result->reason;

            // Deliberately NOT regenerated on a refusal (a typo shouldn't
            // force re-reading a brand new phrase) — only on success or a
            // changed selection, see regeneratePhrase()'s call sites.
            return;
        }

        $this->flashType = 'success';
        $this->flashMessage = 'Cleared '.array_sum($result->rowCounts).' row(s) across '.count($this->selectedModules).' module(s). '
            .'Backup: '.$result->backupPath.'. Operation '.$result->operationId.'.';
        $this->lastOperationId = $result->operationId;
        $this->selectedModules = [];
        $this->regeneratePhrase();
    }

    public function render()
    {
        return view('livewire.operations.data-clear', [
            'modules' => DataClearCatalog::MODULES,
        ])->layout('layouts.admin', ['title' => 'Clear Data']);
    }
}
