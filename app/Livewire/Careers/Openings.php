<?php

namespace App\Livewire\Careers;

use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\SlugRedirect;
use App\Services\ActivityLogger;
use App\Services\SettingsAuditor;
use App\Support\InfoPages\CareersPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Admin → Website & Pages → Careers page (owner 2026-11-21): the jobs on /careers. Add, edit, open/close, reorder and
 * remove a job, and switch the whole page on or off. Behind `careers.manage`, checked on mount and on every action.
 * A changed web address 301s from the old one (SlugRedirect scope `job`); every change is audit-logged.
 */
class Openings extends Component
{
    public bool $pageEnabled = true;

    public ?int $editingId = null;

    public bool $showForm = false;

    public string $title = '';

    public string $slug = '';

    public string $team = '';

    public string $employmentType = 'full_time';

    public string $location = 'Nellore';

    public string $region = 'Andhra Pradesh';

    public string $openings = '1';

    public string $payText = '';

    public string $summary = '';

    public string $duties = '';

    public string $requirements = '';

    public string $perks = '';

    public bool $needsTwoWheeler = false;

    public string $applyBy = '';

    public bool $isOpen = true;

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeManage();
        $this->pageEnabled = CareersPage::enabled();
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermission('careers.manage'), 403, 'You do not have permission to manage the Careers page.');
    }

    public function togglePage(): void
    {
        $this->authorizeManage();
        $this->pageEnabled = ! CareersPage::enabled();
        SettingsAuditor::put(auth()->user(), CareersPage::ENABLED, $this->pageEnabled ? '1' : '0');
        $this->flashMessage = $this->pageEnabled ? 'Careers page switched on.' : 'Careers page switched off.';
    }

    public function create(): void
    {
        $this->authorizeManage();
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $job = JobOpening::findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $job->id;
        $this->title = $job->title;
        $this->slug = $job->slug;
        $this->team = (string) $job->team;
        $this->employmentType = $job->employment_type;
        $this->location = $job->location;
        $this->region = (string) $job->region;
        $this->openings = (string) $job->openings;
        $this->payText = (string) $job->pay_text;
        $this->summary = (string) $job->summary;
        $this->duties = implode("\n", $job->duties ?? []);
        $this->requirements = implode("\n", $job->requirements ?? []);
        $this->perks = implode("\n", $job->perks ?? []);
        $this->needsTwoWheeler = $job->needs_two_wheeler;
        $this->applyBy = $job->apply_by?->toDateString() ?? '';
        $this->isOpen = $job->is_open;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'showForm', 'title', 'slug', 'team', 'employmentType', 'location', 'region', 'openings',
            'payText', 'summary', 'duties', 'requirements', 'perks', 'needsTwoWheeler', 'applyBy', 'isOpen']);
        $this->resetErrorBag();
    }

    /** One line per item; blank lines dropped. */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map(fn ($l) => trim($l), preg_split('/\R/', $text)), fn ($l) => $l !== ''));
    }

    public function save(): void
    {
        $this->authorizeManage();
        $this->slug = trim(strtolower($this->slug));

        $this->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'team' => ['nullable', Rule::in(CareersPage::teams())],
            'employmentType' => ['required', Rule::in(array_keys(JobOpening::TYPES))],
            'location' => ['required', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'openings' => ['required', 'integer', 'min:1', 'max:999'],
            'payText' => ['nullable', 'string', 'max:120'],
            'summary' => ['required', 'string', 'max:600'],
            'duties' => ['nullable', 'string', 'max:4000'],
            'requirements' => ['nullable', 'string', 'max:4000'],
            'perks' => ['nullable', 'string', 'max:4000'],
            'applyBy' => ['nullable', 'date'],
        ], [
            'slug.regex' => 'Use small letters, numbers and dashes only (for example customer-support-executive).',
        ]);

        $slug = $this->slug !== '' ? $this->slug : JobOpening::uniqueSlug($this->title, $this->editingId);
        $taken = JobOpening::withTrashed()->where('slug', $slug)->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->exists()
            || SlugRedirect::query()->where('scope', 'job')->where('old_slug', $slug)->where('target_id', '!=', (int) $this->editingId)->exists();
        if ($taken) {
            $this->addError('slug', 'Another job already uses this web address. Choose a different one.');

            return;
        }

        $data = [
            'title' => trim($this->title),
            'slug' => $slug,
            'team' => $this->team ?: null,
            'employment_type' => $this->employmentType,
            'location' => trim($this->location),
            'region' => trim($this->region) ?: null,
            'openings' => (int) $this->openings,
            'pay_text' => trim($this->payText) ?: null,
            'summary' => trim($this->summary),
            'duties' => self::lines($this->duties),
            'requirements' => self::lines($this->requirements),
            'perks' => self::lines($this->perks),
            'needs_two_wheeler' => $this->needsTwoWheeler,
            'apply_by' => $this->applyBy ?: null,
            'is_open' => $this->isOpen,
        ];

        DB::transaction(function () use ($data) {
            if ($this->editingId) {
                $job = JobOpening::findOrFail($this->editingId);
                $oldSlug = $job->slug;
                $job->update($data);
                if ($oldSlug !== $job->slug) {
                    // The new address must not be shadowed by an older redirect; the old one now points here.
                    SlugRedirect::query()->where('scope', 'job')->where('old_slug', $job->slug)->delete();
                    SlugRedirect::query()->updateOrCreate(['scope' => 'job', 'old_slug' => $oldSlug], ['target_type' => 'job_opening', 'target_id' => $job->id]);
                }
                ActivityLogger::log(auth()->user(), 'job_opening', $job->id, "Job updated: {$job->title}", ['old_slug' => $oldSlug, 'slug' => $job->slug]);
                $this->flashMessage = 'Job saved.';
            } else {
                $job = JobOpening::create($data + ['sort_order' => (int) JobOpening::max('sort_order') + 1]);
                ActivityLogger::log(auth()->user(), 'job_opening', $job->id, "Job added: {$job->title}");
                $this->flashMessage = 'Job added.';
            }
        });

        $this->resetForm();
    }

    public function toggleOpen(int $id): void
    {
        $this->authorizeManage();
        $job = JobOpening::findOrFail($id);
        $job->update(['is_open' => ! $job->is_open]);
        ActivityLogger::log(auth()->user(), 'job_opening', $job->id, ($job->is_open ? 'Job reopened: ' : 'Job closed: ').$job->title);
        $this->flashMessage = $job->is_open ? 'Job reopened.' : 'Job closed. Its page stays up and says it is closed.';
    }

    public function move(int $id, string $direction): void
    {
        $this->authorizeManage();
        $jobs = JobOpening::query()->orderBy('sort_order')->orderBy('id')->get();
        $index = $jobs->search(fn ($j) => $j->id === $id);
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index === false || ! isset($jobs[$swap])) {
            return;
        }
        $ordered = $jobs->values()->all();
        [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
        foreach ($ordered as $i => $job) {
            if ($job->sort_order !== $i + 1) {
                $job->update(['sort_order' => $i + 1]);
            }
        }
    }

    public function delete(int $id): void
    {
        $this->authorizeManage();
        $job = JobOpening::findOrFail($id);
        $job->delete();
        ActivityLogger::log(auth()->user(), 'job_opening', $job->id, "Job removed: {$job->title}");
        $this->flashMessage = 'Job removed. Its applications are kept.';
    }

    public function render()
    {
        return view('livewire.careers.openings', [
            'jobs' => JobOpening::query()->withCount('applications')->orderBy('sort_order')->orderBy('id')->get(),
            'newCounts' => JobApplication::query()->where('status', 'new')->selectRaw('job_opening_id, count(*) as n')->groupBy('job_opening_id')->pluck('n', 'job_opening_id'),
            'teams' => CareersPage::teams(),
            'types' => JobOpening::TYPES,
        ])->layout('layouts.admin', ['title' => 'Careers page']);
    }
}
