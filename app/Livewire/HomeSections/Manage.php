<?php

namespace App\Livewire\HomeSections;

use App\Models\Setting;
use App\Services\SettingsAuditor;
use App\Support\HomeCards;
use App\Support\HomeDoors;
use App\Support\HomeLayout;
use App\Support\SuperAdminGate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Admin → Growth → Homepage sections (owner rule 2026-10-31: every small thing is the Super Admin's):
 *  - Page order: every homepage section, on/off and moved up/down (HomeLayout).
 *  - "Explore 1CallFix" doors: the row on/off, live numbers, the line under the heading, and per door on/off, order
 *    and picture (HomeDoors).
 *  - Business cards: icons per card, how many category cards, the "New" badge window, and per card on/off, order
 *    and the offer line in English + Telugu (HomeCards).
 * Shared words are on Website text & language. Super Admin only; every change is audit-logged.
 * Pictures: PNG/JPEG/WebP only (no SVG upload, it can carry script), public disk.
 */
class Manage extends Component
{
    use WithFileUploads;

    /** @var list<string> */
    public array $order = [];
    /** @var array<string, bool> */
    public array $sectionShow = [];

    public bool $show = true;
    public bool $liveInfo = true;
    public bool $showPromise = true;

    /** @var array<string, bool> */
    public array $doorShow = [];
    /** @var array<string, string> */
    public array $doorOrder = [];
    /** @var array<string, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null> */
    public array $doorImage = [];

    public string $cardIcons = '4';
    public string $cardCategoryLimit = '6';
    public string $cardNewDays = '30';
    /** @var array<string, bool> */
    public array $cardShow = [];
    /** @var array<string, string> */
    public array $cardOrder = [];
    /** @var array<string, string> */
    public array $cardLineEn = [];
    /** @var array<string, string> */
    public array $cardLineTe = [];

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->load();
    }

    private function load(): void
    {
        $this->order = HomeLayout::order();
        foreach (array_keys(HomeLayout::SECTIONS) as $section) {
            $this->sectionShow[$section] = HomeLayout::shown($section);
        }

        $this->show = HomeDoors::show();
        $this->liveInfo = HomeDoors::liveInfo();
        $this->showPromise = HomeDoors::showPromise();
        foreach (array_keys(HomeDoors::DOORS) as $door) {
            $this->doorShow[$door] = HomeDoors::doorShown($door);
            $this->doorOrder[$door] = (string) HomeDoors::doorOrder($door);
            $this->doorImage[$door] = null;
        }

        $this->cardIcons = (string) HomeCards::icons();
        $this->cardCategoryLimit = (string) HomeCards::categoryLimit();
        $this->cardNewDays = (string) HomeCards::newDays();
        foreach ($this->cardList() as $card => $c) {
            $this->cardShow[$card] = HomeCards::cardShown($card);
            $this->cardOrder[$card] = (string) HomeCards::cardOrder($card, $c['index']);
            $this->cardLineEn[$card] = HomeCards::savedLine($card, 'en');
            $this->cardLineTe[$card] = HomeCards::savedLine($card, 'te');
        }
    }

    /**
     * Every card the Super Admin can set: each active service category with services (in catalogue order, so the
     * default order matches the homepage), then Parcel and Shop. A card still shows only where its business is live.
     *
     * @return array<string, array{title: string, index: int}>
     */
    private function cardList(): array
    {
        $list = [];
        $categories = app(\App\Services\Catalog\ServiceCatalogQuery::class)->categories()
            ->whereHas('services', fn ($q) => $q->where('is_active', true))->get();
        foreach ($categories->values() as $i => $category) {
            $list['category_'.$category->id] = ['title' => $category->name, 'index' => $i];
        }
        $list['parcel'] = ['title' => \App\Support\SiteText::get('home.card.parcel.title'), 'index' => 0];
        $list['shop'] = ['title' => \App\Support\SiteText::get('home.card.shop.title'), 'index' => 0];

        return $list;
    }

    public function moveUp(string $section): void
    {
        $this->move($section, -1);
    }

    public function moveDown(string $section): void
    {
        $this->move($section, 1);
    }

    private function move(string $section, int $step): void
    {
        $i = array_search($section, $this->order, true);
        $j = $i === false ? false : $i + $step;
        if ($j === false || $j < 0 || $j >= count($this->order)) {
            return;
        }
        [$this->order[$i], $this->order[$j]] = [$this->order[$j], $this->order[$i]];
        $this->flashMessage = '';
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->validate([
            'order' => ['array'],
            'order.*' => ['in:'.implode(',', array_keys(HomeLayout::SECTIONS))],
            'doorOrder.*' => ['required', 'integer', 'between:1,99'],
            'doorImage.*' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'cardIcons' => ['required', 'in:'.implode(',', HomeCards::ICON_CHOICES)],
            'cardCategoryLimit' => ['required', 'integer', 'between:0,20'],
            'cardNewDays' => ['required', 'integer', 'between:0,365'],
            'cardOrder.*' => ['required', 'integer', 'between:1,999'],
            'cardLineEn.*' => ['nullable', 'string', 'max:120'],
            'cardLineTe.*' => ['nullable', 'string', 'max:120'],
        ], [], [
            'doorOrder.*' => 'order', 'doorImage.*' => 'picture', 'cardOrder.*' => 'order',
            'cardLineEn.*' => 'English line', 'cardLineTe.*' => 'Telugu line',
            'cardIcons' => 'icons per card', 'cardCategoryLimit' => 'category cards', 'cardNewDays' => '"New" days',
        ]);

        $bool = fn (bool $v) => $v ? '1' : '0';
        $admin = auth()->user();
        $changed = false;

        $order = array_values(array_unique($this->order));
        $values = [];
        // The order is saved once it differs from what the page shows now (or once already saved), so the older
        // position settings keep working until the Super Admin actually reorders here.
        if (HomeLayout::hasSavedOrder() || $order !== HomeLayout::order()) {
            $values[HomeLayout::ORDER] = implode(',', $order);
        }
        foreach (array_keys(HomeLayout::SECTIONS) as $section) {
            $values[HomeLayout::showKey($section)] = $bool((bool) ($this->sectionShow[$section] ?? true));
        }

        $values += [
            HomeDoors::SHOW => $bool($this->show),
            HomeDoors::LIVE_INFO => $bool($this->liveInfo),
            HomeDoors::SHOW_PROMISE => $bool($this->showPromise),
        ];
        foreach (array_keys(HomeDoors::DOORS) as $door) {
            $values["home.door.{$door}.show"] = $bool((bool) ($this->doorShow[$door] ?? true));
            $values["home.door.{$door}.order"] = (string) (int) ($this->doorOrder[$door] ?? HomeDoors::DOORS[$door][0]);
        }

        $values += [
            HomeCards::ICONS => $this->cardIcons,
            HomeCards::CATEGORY_LIMIT => (string) (int) $this->cardCategoryLimit,
            HomeCards::NEW_DAYS => (string) (int) $this->cardNewDays,
        ];
        foreach ($this->cardList() as $card => $c) {
            $values[HomeCards::showKey($card)] = $bool((bool) ($this->cardShow[$card] ?? true));
            $values[HomeCards::orderKey($card)] = (string) (int) ($this->cardOrder[$card] ?? HomeCards::defaultOrder($card, $c['index']));
            $values[HomeCards::lineKey($card, 'en')] = trim((string) ($this->cardLineEn[$card] ?? ''));
            $values[HomeCards::lineKey($card, 'te')] = trim((string) ($this->cardLineTe[$card] ?? ''));
        }

        foreach ($values as $key => $value) {
            $changed = SettingsAuditor::put($admin, $key, $value) || $changed;
        }

        foreach (array_keys(HomeDoors::DOORS) as $door) {
            if ($file = $this->doorImage[$door] ?? null) {
                $this->replacePicture($door, $file->store('site/home', 'public'));
                $changed = true;
            }
        }

        $this->forgetAll();
        $this->load();
        $this->flashMessage = $changed ? 'Saved. The homepage shows it now.' : 'Nothing changed.';
    }

    /** Back to the built-in picture for one door. */
    public function resetPicture(string $door): void
    {
        $this->authorizeAdmin();
        abort_unless(array_key_exists($door, HomeDoors::DOORS), 404);
        if (HomeDoors::hasUpload($door)) {
            $this->replacePicture($door, '');
            $this->forgetAll();
            $this->flashMessage = 'Saved. The built-in picture is back.';
        }
    }

    private function replacePicture(string $door, string $path): void
    {
        $key = HomeDoors::imageKey($door);
        $old = trim((string) Setting::get($key, ''));
        SettingsAuditor::put(auth()->user(), $key, $path);
        if ($old !== '' && $old !== $path) {
            Storage::disk('public')->delete($old);
        }
    }

    private function forgetAll(): void
    {
        HomeDoors::forget();
        HomeLayout::forget();
        HomeCards::forget();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change the homepage sections.');
    }

    public function render()
    {
        $doors = collect(array_keys(HomeDoors::DOORS))->mapWithKeys(fn ($door) => [$door => [
            'title' => \App\Support\SiteText::get("home.door.{$door}.title"),
            'image' => HomeDoors::imageUrl($door),
            'uploaded' => HomeDoors::hasUpload($door),
        ]]);

        return view('livewire.home-sections.manage', [
            'doors' => $doors,
            'sectionLabels' => HomeLayout::SECTIONS,
            'orderSaved' => HomeLayout::hasSavedOrder(),
            'cards' => $this->cardList(),
        ])->layout('layouts.admin', ['title' => 'Homepage sections']);
    }
}
