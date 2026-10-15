<?php

namespace App\Livewire\SiteControls;

use App\Services\SettingsAuditor;
use App\Support\SiteControls as S;
use App\Support\SuperAdminGate;
use Livewire\Component;

/**
 * Admin → Growth → Homepage & sign-up (REF 1CF-HOME-CATEGORY-HERO-001). Super Admin only, checked in mount() and on
 * save; every change goes through SettingsAuditor (audit-logged). Covers the homepage category opener, how catalogue
 * images are drawn, the provider sign-up "Services you offer" step and the sign-up attempt limits. Defaults live in
 * App\Support\SiteControls.
 */
class Manage extends Component
{
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
            'servicesStep' => ['required', 'in:'.implode(',', S::SERVICES_MODES)],
            'servicesMax' => ['required', 'integer', 'between:0,50'],
            'partnerFormPerMobile' => ['required', 'integer', 'between:1,100'],
            'partnerFormWindowMinutes' => ['required', 'integer', 'between:1,1440'],
            'otpPerMobile' => ['required', 'integer', 'between:1,50'],
            'submitPerMobile' => ['required', 'integer', 'between:1,100'],
            'windowMinutes' => ['required', 'integer', 'between:1,1440'],
        ], [], [
            'categoryLimit' => 'number of categories', 'columnsMobile' => 'phone columns', 'columnsDesktop' => 'desktop columns',
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

        $this->flashMessage = $changed ? 'Saved. The homepage and sign-up use the new values from the next page load.' : 'Nothing changed.';
    }

    private function authorizeAdmin(): void
    {
        abort_unless(SuperAdminGate::allows(auth()->user()), 403, 'Only a Super Admin can change the homepage and sign-up controls.');
    }

    public function render()
    {
        return view('livewire.site-controls.manage')
            ->layout('layouts.admin', ['title' => 'Homepage & sign-up']);
    }
}
