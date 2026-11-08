<?php

namespace App\Livewire\Customer;

use App\Services\Seo\SeoSettings;
use App\Support\AppLinks;
use App\Support\NativeApp;
use App\Support\SiteText;
use Livewire\Component;

/**
 * /app — the one link to print, post and put in the QR code (owner 2026-11-08). /app?go=1 from an Android phone
 * goes straight to the Play Store; everyone else (and search engines) gets the "Get the app" page. Inside our own
 * app it simply returns to the home screen.
 */
class GetApp extends Component
{
    public function mount()
    {
        if (NativeApp::inApp()) {
            return $this->redirect('/');
        }
        if (request()->query('go') && AppLinks::isAndroid(request()->userAgent())) {
            return redirect()->away(AppLinks::android());
        }
    }

    public function render()
    {
        $brand = SeoSettings::siteName();

        return view('livewire.customer.get-app')->layout('components.layouts.customer', [
            'title' => SiteText::get('get_app.page_title', ['brand' => $brand]),
            'metaDescription' => SiteText::get('get_app.page_meta', ['brand' => $brand]),
            'indexable' => true,
        ]);
    }
}
