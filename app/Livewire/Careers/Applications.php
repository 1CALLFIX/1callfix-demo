<?php

namespace App\Livewire\Careers;

use App\Livewire\PartnerPage\Leads;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Services\ActivityLogger;
use App\Support\Concerns\HasCsvExport;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Users & Partners → Job applications (owner 2026-11-21): who applied on the Careers page. Behind
 * `careers.manage` (checked on mount and on every action). The team sets the status (New → Shortlisted → Interview →
 * Hired / Rejected) and keeps notes, both audit-logged; the CV opens through a permission-gated, logged route; CSV
 * export follows the filters and neutralises spreadsheet formulas. Modelled on PartnerPage\Leads.
 */
class Applications extends Component
{
    use HasCsvExport;
    use WithPagination;

    public const PER_PAGE = 20;

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $jobFilter = '';

    public ?int $notesId = null;

    public string $notes = '';

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeManage();
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermission('careers.manage'), 403, 'You do not have permission to view job applications.');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingJobFilter(): void
    {
        $this->resetPage();
    }

    private function baseQuery(): Builder
    {
        return JobApplication::query()->with('opening')
            ->when(array_key_exists($this->statusFilter, JobApplication::STATUSES), fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->jobFilter === 'any', fn ($q) => $q->whereNull('job_opening_id'))
            ->when(ctype_digit($this->jobFilter), fn ($q) => $q->where('job_opening_id', (int) $this->jobFilter))
            ->orderByDesc('id');
    }

    public function setStatus(int $id, string $status): void
    {
        $this->authorizeManage();
        if (! array_key_exists($status, JobApplication::STATUSES)) {
            $this->addError('status', 'Unknown status.');

            return;
        }
        $row = JobApplication::findOrFail($id);
        $old = $row->status;
        if ($old === $status) {
            return;
        }
        $row->update(['status' => $status]);
        ActivityLogger::log(auth()->user(), 'job_application', $row->id, "Job application status {$old} → {$status}", ['old' => $old, 'new' => $status]);
        $this->resetErrorBag();
        $this->flashMessage = 'Status updated.';
    }

    public function openNotes(int $id): void
    {
        $this->authorizeManage();
        $this->notesId = $id;
        $this->notes = (string) JobApplication::findOrFail($id)->admin_notes;
    }

    public function saveNotes(): void
    {
        $this->authorizeManage();
        $this->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $row = JobApplication::findOrFail((int) $this->notesId);
        $row->update(['admin_notes' => trim($this->notes) ?: null]);
        ActivityLogger::log(auth()->user(), 'job_application', $row->id, 'Job application notes updated');
        $this->reset(['notesId', 'notes']);
        $this->flashMessage = 'Notes saved.';
    }

    public function exportCsv()
    {
        $this->authorizeManage();
        $query = $this->baseQuery();
        ActivityLogger::log(auth()->user(), 'job_applications_export', 0, 'Job applications exported', [
            'rows' => (clone $query)->count(),
            'filters' => ['status' => $this->statusFilter ?: null, 'job' => $this->jobFilter ?: null],
        ]);

        return $this->streamCsvExport(
            'job-applications-'.now()->format('Y-m-d-His').'.csv',
            $query->reorder(),
            ['id', 'job', 'name', 'phone', 'email', 'city', 'experience', 'current_work', 'two_wheeler', 'has_cv', 'message', 'status', 'notes', 'utm_source', 'applied_at', 'updated_at'],
            fn (JobApplication $a) => array_map([Leads::class, 'csvSafe'], [
                $a->id, $a->jobTitle(), $a->name, $a->phone, (string) $a->email, (string) $a->city,
                JobApplication::EXPERIENCE[$a->experience] ?? (string) $a->experience, (string) $a->current_work,
                $a->two_wheeler === null ? '' : ($a->two_wheeler ? 'yes' : 'no'), $a->cv_path ? 'yes' : 'no',
                (string) $a->message, $a->status, (string) $a->admin_notes, $a->acquisition['utm_source'] ?? '',
                (string) $a->created_at, (string) $a->updated_at,
            ]),
        );
    }

    public function render()
    {
        return view('livewire.careers.applications', [
            'applications' => $this->baseQuery()->paginate(self::PER_PAGE),
            'statuses' => JobApplication::STATUSES,
            'jobs' => JobOpening::withTrashed()->orderBy('sort_order')->get(['id', 'title', 'deleted_at']),
            'newCount' => JobApplication::query()->where('status', 'new')->count(),
        ])->layout('layouts.admin', ['title' => 'Job applications']);
    }
}
