<?php

namespace App\Livewire\PartnerDocuments;

use App\Models\KycDocumentRequirement;
use App\Services\ActivityLogger;
use App\Support\SuperAdminGate;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Partners part B (owner 2026-11-12): Super Admin decides which documents each kind of business uploads before approval
 * — rename, required / if you have it, on / off, add one. These rows are the ones sign-up, "Go live" and the approval
 * check (KycDocumentService::requirementsForSubject) all read. Country-specific overrides stay as they are.
 */
class Manage extends Component
{
    public const TYPES = [
        'provider' => 'Service providers', 'field_worker' => 'Delivery riders', 'hotel' => 'Hotels and stays',
        'restaurant' => 'Restaurants', 'shop' => 'Shops', 'organiser' => 'Event organisers', 'hall' => 'Function halls',
    ];

    #[Url(as: 'for')]
    public string $type = 'hotel';

    /** @var array<int, array{label: string, is_required: bool, is_active: bool}> */
    public array $rows = [];

    public string $newLabel = '';

    public bool $newRequired = true;

    public string $notice = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
        if (! array_key_exists($this->type, self::TYPES)) {
            $this->type = 'hotel';
        }
        $this->load();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change the partner documents.');
    }

    private function load(): void
    {
        $this->rows = KycDocumentRequirement::where('applicable_type', $this->type)->whereNull('country_id')->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn ($r) => [$r->id => ['label' => $r->label, 'is_required' => (bool) $r->is_required, 'is_active' => (bool) $r->is_active]])->all();
    }

    public function updatedType(): void
    {
        $this->type = array_key_exists($this->type, self::TYPES) ? $this->type : 'hotel';
        $this->reset('notice', 'newLabel');
        $this->resetValidation();
        $this->load();
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->validate(['rows.*.label' => ['required', 'string', 'min:3', 'max:120']], [], ['rows.*.label' => 'name']);
        $changed = 0;
        foreach ($this->rows as $id => $row) {
            $req = KycDocumentRequirement::where('applicable_type', $this->type)->whereNull('country_id')->find((int) $id);
            if (! $req) {
                continue;
            }
            $req->fill(['label' => trim($row['label']), 'is_required' => (bool) $row['is_required'], 'is_active' => (bool) $row['is_active']]);
            if ($req->isDirty()) {
                $before = $req->getOriginal();
                $req->save();
                $changed++;
                ActivityLogger::logModel(auth()->user(), $req, 'Partner document changed', ['type' => $this->type, 'before' => array_intersect_key($before, array_flip(['label', 'is_required', 'is_active'])), 'after' => $req->only(['label', 'is_required', 'is_active'])]);
            }
        }
        $this->notice = $changed ? 'Saved.' : 'Nothing changed.';
    }

    public function add(): void
    {
        $this->authorizeAdmin();
        $this->validate(['newLabel' => ['required', 'string', 'min:3', 'max:120']], [], ['newLabel' => 'document name']);
        $code = Str::of($this->newLabel)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->value() ?: 'document';
        $base = $code;
        for ($n = 2; KycDocumentRequirement::where('applicable_type', $this->type)->where('document_type', $code)->whereNull('country_id')->exists(); $n++) {
            $code = $base.'_'.$n;
        }
        $req = KycDocumentRequirement::create([
            'applicable_type' => $this->type, 'document_type' => $code, 'label' => trim($this->newLabel),
            'is_required' => $this->newRequired, 'is_active' => true, 'country_id' => null,
            'sort_order' => (int) KycDocumentRequirement::where('applicable_type', $this->type)->max('sort_order') + 1,
        ]);
        ActivityLogger::logModel(auth()->user(), $req, 'Partner document added', ['type' => $this->type, 'label' => $req->label, 'required' => $req->is_required]);
        $this->reset('newLabel');
        $this->newRequired = true;
        $this->load();
        $this->notice = 'Added.';
    }

    public function render()
    {
        return view('livewire.partner-documents.manage', ['types' => self::TYPES])
            ->layout('layouts.admin', ['title' => 'Partner documents']);
    }
}
