<?php

namespace App\Livewire\Seo;

use App\Models\City;
use App\Models\CityPageContent;
use App\Models\Franchise;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\ActivityLogger;
use App\Support\Modules;
use App\Support\Seo\QaRows;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * F3: per-city page copy (title, meta description, intro) for the city page, a category page or a service page.
 *
 * Permission `seo.edit_city_content`, checked server-side on every action:
 *  - a franchise-scoped holder may edit only a city that has one of THEIR franchises
 *  - an HQ (global) holder or Super Admin may edit any city
 * Slugs are never editable here; those stay with catalog.edit_slugs / Super Admin (SlugManager).
 */
class CityContent extends Component
{
    public const PERMISSION = 'seo.edit_city_content';

    public ?int $cityId = null;
    public string $subjectType = CityPageContent::SUBJECT_CITY;
    public ?int $subjectId = null;

    public string $title = '';
    public string $metaDescription = '';
    public string $intro = '';

    public string $flashMessage = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere(self::PERMISSION), 403, 'You do not have permission to edit city page content.');
    }

    /** @return Collection<int, City> the cities this user may edit */
    private function allowedCities(): Collection
    {
        $user = auth()->user();

        return City::query()->orderBy('name')->get()->filter(function (City $city) use ($user) {
            if ($user->hasPermission(self::PERMISSION)) {
                return true; // global / super admin
            }

            return Franchise::query()->where('city_id', $city->id)->pluck('id')
                ->contains(fn ($franchiseId) => $user->hasPermission(self::PERMISSION, ['franchise_id' => $franchiseId]));
        })->values();
    }

    private function authorizeCity(): City
    {
        $city = City::findOrFail((int) $this->cityId);
        abort_unless($this->allowedCities()->contains('id', $city->id), 403, 'You cannot edit content for this city.');

        return $city;
    }

    public function updatedCityId(): void
    {
        $this->subjectType = CityPageContent::SUBJECT_CITY;
        $this->subjectId = null;
        $this->loadRow();
    }

    public function updatedSubjectType(): void
    {
        $this->subjectId = null;
        $this->loadRow();
    }

    public function updatedSubjectId(): void
    {
        $this->loadRow();
    }

    private function loadRow(): void
    {
        $this->flashMessage = '';
        $this->resetValidation();

        if (! $this->cityId || ($this->subjectType !== CityPageContent::SUBJECT_CITY && ! $this->subjectId)) {
            $this->title = $this->metaDescription = $this->intro = '';

            return;
        }

        $this->authorizeCity();

        $row = CityPageContent::query()
            ->where('city_id', $this->cityId)->where('subject_type', $this->subjectType)
            ->where('subject_id', $this->subjectKey())->first();

        $this->title = (string) $row?->title;
        $this->metaDescription = (string) $row?->meta_description;
        $this->intro = (string) $row?->intro;
    }

    private function subjectKey(): int
    {
        return $this->subjectType === CityPageContent::SUBJECT_CITY ? 0 : (int) $this->subjectId;
    }

    public function save(): void
    {
        $city = $this->authorizeCity();

        abort_unless(
            in_array($this->subjectType, [CityPageContent::SUBJECT_CITY, CityPageContent::SUBJECT_CATEGORY, CityPageContent::SUBJECT_SERVICE], true),
            422,
        );

        if ($this->subjectType === CityPageContent::SUBJECT_CATEGORY) {
            ServiceCategory::findOrFail((int) $this->subjectId);
        } elseif ($this->subjectType === CityPageContent::SUBJECT_SERVICE) {
            Service::findOrFail((int) $this->subjectId);
        }

        $this->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'metaDescription' => ['nullable', 'string', 'max:320'],
            'intro' => ['nullable', 'string', 'max:4000'],
        ]);

        $key = ['city_id' => $city->id, 'subject_type' => $this->subjectType, 'subject_id' => $this->subjectKey()];
        $existing = CityPageContent::query()->where($key)->first();
        $new = [
            'title' => trim($this->title) ?: null,
            'meta_description' => trim($this->metaDescription) ?: null,
            'intro' => trim($this->intro) ?: null,
        ];

        $row = CityPageContent::query()->updateOrCreate($key, $new + ['updated_by' => auth()->id()]);

        ActivityLogger::log(auth()->user(), 'city_page_content', (int) $row->id, 'City page content saved', [
            'city_id' => $city->id,
            'subject' => $this->subjectType.':'.$this->subjectKey(),
            'old' => $existing?->only(['title', 'meta_description', 'intro']),
            'new' => $new,
        ]);

        $this->flashMessage = 'Saved.';
    }

    public function render()
    {
        $subjects = collect();
        if ($this->subjectType === CityPageContent::SUBJECT_CATEGORY) {
            $subjects = ServiceCategory::query()->where('module', Modules::SERVICE)->where('name', 'not like', QaRows::LIKE)
                ->orderBy('name')->get(['id', 'name']);
        } elseif ($this->subjectType === CityPageContent::SUBJECT_SERVICE) {
            $subjects = Service::query()->where('name', 'not like', QaRows::LIKE)->orderBy('name')->get(['id', 'name']);
        }

        return view('livewire.seo.city-content', [
            'cities' => $this->allowedCities(),
            'subjects' => $subjects,
        ])->layout('layouts.admin', ['title' => 'City page content']);
    }
}
