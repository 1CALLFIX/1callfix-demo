<?php

namespace App\Livewire\Marketing;

use App\Models\City;
use App\Models\Franchise;
use App\Models\MarketingContact;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use App\Services\Marketing\ContactImporter;
use App\Services\Marketing\ContactInviter;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Admin → Marketing & Offers → Contacts & app invites (owner 2026-11-21): old customers who are not on the app yet.
 * Import an Excel / CSV list (preview first), invite by email (queued, once, with unsubscribe) or by WhatsApp from the
 * company phone (one tap each), and see who Joined. Behind `marketing.contacts.manage`, row-scoped by city /
 * franchise / country; a city team's imports go to its own city. Audited.
 */
class Contacts extends Component
{
    use WithFileUploads;
    use WithPagination;

    public const FILTERS = ['all' => 'All', 'new' => 'Not invited', 'invited' => 'Invited', 'joined' => 'Joined ✓', 'unsubscribed' => 'Unsubscribed'];

    #[Url]
    public string $filter = 'all';

    #[Url]
    public string $search = '';

    public $file = null;

    public ?array $preview = null;

    public ?int $importCityId = null;

    public string $flashMessage = '';

    public string $flashType = 'success';

    public function mount(): void
    {
        $this->authorizeManage();
        $this->importCityId = $this->ownCityId();
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionAnywhere('marketing.contacts.manage'), 403, 'You do not have permission to manage contacts.');
    }

    private function contacts(): Builder
    {
        return app(AuthorizationService::class)->scopeQuery(MarketingContact::query(), auth()->user(), 'marketing.contacts.manage',
            ['franchise_id' => 'franchise_id', 'city_id' => 'city_id', 'country_id' => 'country_id']);
    }

    /** The city a city / franchise team works in (null for Super Admin and HQ). */
    private function ownCityId(): ?int
    {
        $user = auth()->user();
        if ($user->role === 'super_admin') {
            return null;
        }
        $a = $user->roleAssignments()->whereIn('scope_type', ['city', 'franchise'])->first();

        return $a ? ($a->scope_type === 'city' ? $a->scope_id : Franchise::find($a->scope_id)?->city_id) : null;
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFile(): void
    {
        $this->authorizeManage();
        $this->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls']], [
            'file.mimes' => 'Pick an Excel file (.xlsx, .xls) or a CSV file.',
        ]);
        try {
            $this->preview = app(ContactImporter::class)->preview($this->file->getRealPath());
        } catch (\Throwable $e) {
            $this->preview = null;
            $this->addError('file', 'That file could not be read. Save it again from Excel as .xlsx or CSV and try once more.');
        }
    }

    public function cancelImport(): void
    {
        $this->reset(['file', 'preview']);
    }

    public function import(): void
    {
        $this->authorizeManage();
        if (! $this->preview || $this->preview['rows'] === []) {
            return;
        }
        $own = $this->ownCityId();
        $cityId = $own ?? $this->importCityId;
        // A city team imports into its own city, whatever the file says.
        $rows = $own ? array_map(fn ($r) => array_merge($r, ['city' => null]), $this->preview['rows']) : $this->preview['rows'];

        $result = app(ContactImporter::class)->save($rows, auth()->user(), $cityId);
        ActivityLogger::log(auth()->user(), 'marketing_contacts', 0, 'Contacts imported', $result);
        $this->reset(['file', 'preview']);
        $this->flashMessage = "{$result['added']} contacts added".($result['updated'] ? ", {$result['updated']} updated" : '')
            .($result['already_customers'] ? ", {$result['already_customers']} are already on the app (marked Joined)" : '').'.';
        $this->flashType = 'success';
    }

    public function emailAll(): void
    {
        $this->authorizeManage();
        $sent = app(ContactInviter::class)->emailAll($this->filtered(), auth()->user());
        $this->flashMessage = $sent > 0 ? "App invitation is being emailed to {$sent} contacts." : 'Nobody left to email: everyone with an email was already invited, joined or unsubscribed.';
        $this->flashType = $sent > 0 ? 'success' : 'info';
    }

    public function markWhatsapp(int $id): void
    {
        $this->authorizeManage();
        $contact = $this->contacts()->findOrFail($id);
        $contact->forceFill(['whatsapp_invited_at' => now()])->save();
        ActivityLogger::logModel(auth()->user(), $contact, 'App invitation sent on WhatsApp');
    }

    public function delete(int $id): void
    {
        $this->authorizeManage();
        $contact = $this->contacts()->findOrFail($id);
        ActivityLogger::logModel(auth()->user(), $contact, 'Contact deleted', ['phone' => $contact->phone]);
        $contact->delete();
    }

    private function filtered(): Builder
    {
        $q = $this->contacts();
        if (trim($this->search) !== '') {
            $s = '%'.trim($this->search).'%';
            $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('phone', 'like', $s)->orWhere('email', 'like', $s));
        }

        return match ($this->filter) {
            'new' => $q->whereNull('email_invited_at')->whereNull('whatsapp_invited_at')->whereNull('joined_user_id')->whereNull('unsubscribed_at'),
            'invited' => $q->where(fn ($w) => $w->whereNotNull('email_invited_at')->orWhereNotNull('whatsapp_invited_at'))->whereNull('joined_user_id'),
            'joined' => $q->whereNotNull('joined_user_id'),
            'unsubscribed' => $q->whereNotNull('unsubscribed_at'),
            default => $q,
        };
    }

    public function render()
    {
        $this->authorizeManage();
        $all = $this->contacts();

        return view('livewire.marketing.contacts', [
            'contacts' => $this->filtered()->with('joinedUser:id,name')->latest('id')->paginate(25),
            'stats' => [
                'total' => (clone $all)->count(),
                'email' => (clone $all)->whereNotNull('email')->count(),
                'invited' => (clone $all)->where(fn ($w) => $w->whereNotNull('email_invited_at')->orWhereNotNull('whatsapp_invited_at'))->count(),
                'joined' => (clone $all)->whereNotNull('joined_user_id')->count(),
            ],
            'emailable' => ContactInviter::emailable($this->filtered())->count(),
            'message' => ContactInviter::message(),
            'filters' => self::FILTERS,
            'cities' => $this->ownCityId() ? collect() : City::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.admin', ['title' => 'Contacts & app invites']);
    }
}
