<?php

namespace App\Livewire\PartnerPage;

use App\Models\PartnerLead;
use App\Services\ActivityLogger;
use App\Support\Concerns\HasCsvExport;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Growth → Partner leads (REF 1CF-PARTNER-PAGE-001, B6). A read-only list of what people submitted on
 * /partners, behind `partner_page.manage` (checked on mount, so it holds for a direct Livewire call too). The only
 * write is the lead's status, which is validated and audit-logged. CSV export reuses the shared HasCsvExport
 * mechanism, follows the on-screen filters, is permission-gated and logged, and neutralises spreadsheet formulas.
 * All output is escaped by Blade.
 */
class Leads extends Component
{
    use HasCsvExport;
    use WithPagination;

    public const PER_PAGE = 20;

    public string $statusFilter = '';

    public string $roleFilter = '';

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeManage();
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermission('partner_page.manage'), 403, 'You do not have permission to view partner leads.');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingRoleFilter(): void
    {
        $this->resetPage();
    }

    private function baseQuery(): Builder
    {
        return PartnerLead::query()
            ->when(in_array($this->statusFilter, PartnerLead::STATUSES, true), fn ($q) => $q->where('status', $this->statusFilter))
            ->when(in_array($this->roleFilter, Modules::slugs(), true), fn ($q) => $q->where('role', $this->roleFilter))
            ->orderByDesc('id');
    }

    public function setStatus(int $id, string $status): void
    {
        $this->authorizeManage();

        if (! in_array($status, PartnerLead::STATUSES, true)) {
            $this->addError('status', 'Unknown status.');

            return;
        }

        $lead = PartnerLead::findOrFail($id);
        $old = $lead->status;
        if ($old === $status) {
            return;
        }

        $lead->update(['status' => $status]);
        ActivityLogger::log(auth()->user(), 'partner_lead', $lead->id, "Partner lead status {$old} → {$status}", ['old' => $old, 'new' => $status]);
        $this->resetErrorBag();
        $this->flashMessage = 'Status updated.';
    }

    /** CSV cells that start with a formula character get a leading apostrophe so a spreadsheet shows them as text. */
    public static function csvSafe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    public function exportCsv()
    {
        $this->authorizeManage();

        $query = $this->baseQuery();
        ActivityLogger::log(auth()->user(), 'partner_leads_export', 0, 'Partner leads exported', [
            'rows' => (clone $query)->count(),
            'filters' => ['status' => $this->statusFilter ?: null, 'role' => $this->roleFilter ?: null],
        ]);

        return $this->streamCsvExport(
            'partner-leads-'.now()->format('Y-m-d-His').'.csv',
            $query->reorder(),
            ['id', 'name', 'phone', 'city', 'role', 'status', 'source', 'utm_source', 'utm_campaign', 'consent_at', 'submit_count', 'created_at', 'updated_at'],
            fn (PartnerLead $l) => array_map([self::class, 'csvSafe'], [
                $l->id, $l->name, $l->phone, $l->city, $l->role, $l->status, $l->source,
                $l->acquisition['utm_source'] ?? '', $l->acquisition['utm_campaign'] ?? '',
                (string) $l->consent_at, $l->submit_count, (string) $l->created_at, (string) $l->updated_at,
            ]),
        );
    }

    public function render()
    {
        return view('livewire.partner-page.leads', [
            'leads' => $this->baseQuery()->paginate(self::PER_PAGE),
            'statuses' => PartnerLead::STATUSES,
            'roles' => Modules::ALL,
        ])->layout('layouts.admin', ['title' => 'Partner leads']);
    }
}
