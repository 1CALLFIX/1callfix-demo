<?php

namespace App\Livewire\SiteControls;

use App\Services\SettingsAuditor;
use App\Support\SiteControls as S;
use App\Support\SuperAdminGate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Admin → Growth → Homepage & sign-up (REF 1CF-HOME-CATEGORY-HERO-001). Super Admin only, checked in mount() and on
 * save; every change goes through SettingsAuditor (audit-logged). Covers the homepage category opener, how catalogue
 * images are drawn, the homepage parcel card (owner 2026-10-26: position, the Parcel icon in the grid, title, picture,
 * colour, price badge), the provider sign-up "Services you offer" step and the sign-up attempt limits. Defaults live in
 * App\Support\SiteControls. Uploaded pictures go to the public disk (PNG/JPEG/WebP only: no SVG, it can carry script).
 */
class Manage extends Component
{
    use WithFileUploads;

    public string $headline = '';
    public bool $showHeadline = true;
    public bool $categoriesFirst = true;
    public bool $showPhotoTiles = false;
    public string $categoryLimit = '16';
    public string $columnsMobile = '4';
    public string $columnsDesktop = '8';
    public string $iconSize = 'medium';

    public string $imageFit = 'contain';
    public bool $imageBorder = true;
    public bool $imageGloss = true;

    public string $parcelPosition = 'after_banner';
    public bool $parcelGridTile = true;
    public bool $parcelNewBadge = true;
    public string $parcelTitle = '';
    public string $parcelSubtitle = '';
    public bool $parcelShowPrice = true;
    public string $parcelColour = 'blue';
    public bool $parcelShowChips = true;
    public string $parcelChips = '';
    public string $parcelSendLabel = '';
    public string $parcelTrackLabel = '';
    public string $parcelBadgeText = '';
    public string $parcelPageHeading = '';
    public string $parcelPageIntro = '';
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $parcelImageFile = null;
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $parcelIconFile = null;

    public string $servicesStep = 'required';
    public string $servicesMax = '0';

    public string $partnerFormPerMobile = '10';
    public string $partnerFormWindowMinutes = '10';
    public string $otpPerMobile = '5';
    public string $submitPerMobile = '5';
    public string $windowMinutes = '1';

    public string $flashMessage = '';

    public function mount(): void
    {
        $this->authorizeAdmin();

        $this->headline = S::headline();
        $this->showHeadline = S::showHeadline();
        $this->categoriesFirst = S::categoriesFirst();
        $this->showPhotoTiles = S::showPhotoTiles();
        $this->categoryLimit = (string) S::categoryLimit();
        $this->columnsMobile = (string) S::columnsMobile();
        $this->columnsDesktop = (string) S::columnsDesktop();
        $this->iconSize = S::iconSize();
        $this->imageFit = S::imageFit();
        $this->imageBorder = S::imageBorder();
        $this->imageGloss = S::imageGloss();
        $this->parcelPosition = S::parcelPosition();
        $this->parcelGridTile = S::parcelGridTile();
        $this->parcelNewBadge = S::parcelNewBadge();
        $this->parcelTitle = S::parcelTitle();
        $this->parcelSubtitle = S::parcelSubtitle();
        $this->parcelShowPrice = S::parcelShowPrice();
        $this->parcelColour = S::parcelColour();
        $this->parcelShowChips = S::parcelShowChips();
        $this->parcelChips = S::parcelChipsText();
        $this->parcelSendLabel = S::parcelSendLabel();
        $this->parcelTrackLabel = S::parcelTrackLabel();
        $this->parcelBadgeText = S::parcelBadgeText();
        $this->parcelPageHeading = S::parcelPageHeading();
        $this->parcelPageIntro = S::parcelPageIntro();
        $this->servicesStep = S::servicesStep();
        $this->servicesMax = (string) S::servicesMax();
        $this->partnerFormPerMobile = (string) S::partnerFormPerMobile();
        $this->partnerFormWindowMinutes = (string) intdiv(S::partnerFormWindowSeconds(), 60);
        $this->otpPerMobile = (string) S::otpPerMobile();
        $this->submitPerMobile = (string) S::submitPerMobile();
        $this->windowMinutes = (string) intdiv(S::windowSeconds(), 60);
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'headline' => ['required', 'string', 'max:120'],
            'categoryLimit' => ['required', 'integer', 'between:4,40'],
            'columnsMobile' => ['required', 'integer', 'between:3,6'],
            'iconSize' => ['required', 'in:'.implode(',', S::ICON_SIZES)],
            'columnsDesktop' => ['required', 'integer', 'between:4,12'],
            'imageFit' => ['required', 'in:'.implode(',', S::FITS)],
            'parcelPosition' => ['required', 'in:'.implode(',', S::PARCEL_POSITIONS)],
            'parcelTitle' => ['required', 'string', 'max:40'],
            'parcelSubtitle' => ['required', 'string', 'max:80'],
            'parcelColour' => ['required', 'in:'.implode(',', S::PARCEL_COLOURS)],
            'parcelChips' => ['required', 'string', 'max:160'],
            'parcelSendLabel' => ['required', 'string', 'max:30'],
            'parcelTrackLabel' => ['required', 'string', 'max:30'],
            'parcelBadgeText' => ['required', 'string', 'max:10'],
            'parcelPageHeading' => ['required', 'string', 'max:60'],
            'parcelPageIntro' => ['required', 'string', 'max:200'],
            'parcelImageFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'parcelIconFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'servicesStep' => ['required', 'in:'.implode(',', S::SERVICES_MODES)],
            'servicesMax' => ['required', 'integer', 'between:0,50'],
            'partnerFormPerMobile' => ['required', 'integer', 'between:1,100'],
            'partnerFormWindowMinutes' => ['required', 'integer', 'between:1,1440'],
            'otpPerMobile' => ['required', 'integer', 'between:1,50'],
            'submitPerMobile' => ['required', 'integer', 'between:1,100'],
            'windowMinutes' => ['required', 'integer', 'between:1,1440'],
        ], [], [
            'categoryLimit' => 'number of categories', 'columnsMobile' => 'phone columns', 'columnsDesktop' => 'desktop columns',
            'parcelTitle' => 'parcel title', 'parcelSubtitle' => 'parcel subtitle', 'parcelImageFile' => 'parcel picture', 'parcelIconFile' => 'parcel icon',
            'parcelChips' => 'chips', 'parcelSendLabel' => 'send button', 'parcelTrackLabel' => 'track button', 'parcelBadgeText' => 'icon label',
            'parcelPageHeading' => 'parcel page heading', 'parcelPageIntro' => 'parcel page line',
            'servicesMax' => 'most services', 'partnerFormPerMobile' => 'partner form tries', 'partnerFormWindowMinutes' => 'partner form window',
            'otpPerMobile' => 'code requests', 'submitPerMobile' => 'submit tries', 'windowMinutes' => 'sign-up window',
        ]);

        $bool = fn (bool $v) => $v ? '1' : '0';
        $admin = auth()->user();
        $changed = false;
        foreach ([
            S::HOME_HEADLINE => trim($this->headline),
            S::HOME_SHOW_HEADLINE => $bool($this->showHeadline),
            S::HOME_CATEGORIES_FIRST => $bool($this->categoriesFirst),
            S::HOME_SHOW_PHOTOS => $bool($this->showPhotoTiles),
            S::HOME_CATEGORY_LIMIT => (string) (int) $this->categoryLimit,
            S::HOME_COLUMNS_MOBILE => (string) (int) $this->columnsMobile,
            S::HOME_COLUMNS_DESKTOP => (string) (int) $this->columnsDesktop,
            S::HOME_ICON_SIZE => $this->iconSize,
            S::IMAGE_FIT => $this->imageFit,
            S::IMAGE_BORDER => $bool($this->imageBorder),
            S::IMAGE_GLOSS => $bool($this->imageGloss),
            S::PARCEL_POSITION => $this->parcelPosition,
            S::PARCEL_GRID_TILE => $bool($this->parcelGridTile),
            S::PARCEL_NEW_BADGE => $bool($this->parcelNewBadge),
            S::PARCEL_TITLE => trim($this->parcelTitle),
            S::PARCEL_SUBTITLE => trim($this->parcelSubtitle),
            S::PARCEL_SHOW_PRICE => $bool($this->parcelShowPrice),
            S::PARCEL_COLOUR => $this->parcelColour,
            S::PARCEL_SHOW_CHIPS => $bool($this->parcelShowChips),
            S::PARCEL_CHIPS => trim($this->parcelChips),
            S::PARCEL_SEND_LABEL => trim($this->parcelSendLabel),
            S::PARCEL_TRACK_LABEL => trim($this->parcelTrackLabel),
            S::PARCEL_BADGE_TEXT => trim($this->parcelBadgeText),
            S::PARCEL_PAGE_HEADING => trim($this->parcelPageHeading),
            S::PARCEL_PAGE_INTRO => trim($this->parcelPageIntro),
            S::SIGNUP_SERVICES => $this->servicesStep,
            S::SIGNUP_SERVICES_MAX => (string) (int) $this->servicesMax,
            S::LIMIT_PARTNER_FORM => (string) (int) $this->partnerFormPerMobile,
            S::LIMIT_PARTNER_WINDOW => (string) (int) $this->partnerFormWindowMinutes,
            S::LIMIT_OTP => (string) (int) $this->otpPerMobile,
            S::LIMIT_SUBMIT => (string) (int) $this->submitPerMobile,
            S::LIMIT_WINDOW => (string) (int) $this->windowMinutes,
        ] as $key => $value) {
            $changed = SettingsAuditor::put($admin, $key, $value) || $changed;
        }

        foreach ([S::PARCEL_IMAGE => 'parcelImageFile', S::PARCEL_ICON => 'parcelIconFile'] as $key => $field) {
            if ($this->{$field}) {
                $this->replacePicture($key, $this->{$field}->store('site/parcel', 'public'));
                $this->{$field} = null;
                $changed = true;
            }
        }

        $this->flashMessage = $changed ? 'Saved. The homepage and sign-up use the new values from the next page load.' : 'Nothing changed.';
    }

    /** Back to the built-in parcel picture ('image') or icon ('icon'). */
    public function resetParcelPicture(string $which): void
    {
        $this->authorizeAdmin();
        $key = $which === 'icon' ? S::PARCEL_ICON : S::PARCEL_IMAGE;
        if (S::hasUpload($key)) {
            $this->replacePicture($key, '');
            $this->flashMessage = 'Saved. The built-in '.($which === 'icon' ? 'icon' : 'picture').' is back.';
        }
    }

    /** Store the new path (audit-logged) and delete the old upload from the public disk. */
    private function replacePicture(string $key, string $path): void
    {
        $old = trim((string) \App\Models\Setting::get($key, ''));
        SettingsAuditor::put(auth()->user(), $key, $path);
        request()->attributes->remove('site_controls:'.$key);
        if ($old !== '' && $old !== $path) {
            Storage::disk('public')->delete($old);
        }
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change the homepage and sign-up controls.');
    }

    public function render()
    {
        return view('livewire.site-controls.manage', [
            'parcelImageUrl' => S::parcelImageUrl(),
            'parcelIconUrl' => S::parcelIconUrl(),
            'parcelImageUploaded' => S::hasUpload(S::PARCEL_IMAGE),
            'parcelIconUploaded' => S::hasUpload(S::PARCEL_ICON),
        ])
            ->layout('layouts.admin', ['title' => 'Homepage & sign-up']);
    }
}
