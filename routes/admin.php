<?php

use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Bookings\Index as BookingsIndex;
use App\Livewire\Bookings\Show as BookingsShow;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\Providers\Show as ProvidersShow;
use App\Livewire\Franchises\Manage as FranchisesManage;
use App\Livewire\Zones\Manage as ZonesManage;
use App\Livewire\Categories\Manage as CategoriesManage;
use App\Livewire\Subcategories\Manage as SubcategoriesManage;
use App\Livewire\Services\Manage as ServicesManage;
use App\Livewire\Banners\Manage as BannersManage;
use App\Livewire\Settings\Manage as SettingsManage;
use App\Livewire\Cms\Manage as CmsManage;
use App\Livewire\Roles\Manage as RolesManage;
use App\Livewire\Payouts\Manage as PayoutsManage;
use App\Livewire\NotificationCenter\Manage as NotificationCenterManage;
use App\Livewire\Plans\Manage as PlansManage;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/admin/login', Login::class)->name('admin.login');

Route::post('/admin/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('admin.login');
})->middleware('auth')->name('admin.logout');

Route::middleware(['auth', \App\Http\Middleware\EnsureHasAdminAccess::class])
    ->prefix('admin')
    ->group(function () {
        Route::get('/dashboard', Dashboard::class)->name('admin.dashboard');
        Route::get('/bookings', BookingsIndex::class)->name('admin.bookings.index');
        Route::get('/bookings/{bookingId}', BookingsShow::class)->name('admin.bookings.show');
        Route::get('/providers', ProvidersIndex::class)->name('admin.providers.index');
        Route::get('/providers/{providerId}', ProvidersShow::class)->name('admin.providers.show');
        Route::get('/franchises', FranchisesManage::class)->name('admin.franchises.index');
        Route::get('/zones', ZonesManage::class)->name('admin.zones.index');
        Route::get('/categories', CategoriesManage::class)->name('admin.categories.index');
        Route::get('/subcategories', SubcategoriesManage::class)->name('admin.subcategories.index');
        Route::get('/services', ServicesManage::class)->name('admin.services.index');
        Route::get('/banners', BannersManage::class)->name('admin.banners.index');
        Route::get('/settings', SettingsManage::class)->name('admin.settings.index');
        Route::get('/cms', CmsManage::class)->name('admin.cms.index');
        Route::get('/roles', RolesManage::class)->name('admin.roles.index');
        Route::get('/payouts', PayoutsManage::class)->name('admin.payouts.index');
        Route::get('/notifications', NotificationCenterManage::class)->name('admin.notifications.index');
        Route::get('/plans', PlansManage::class)->name('admin.plans.index');
        Route::get('/subscriptions', SubscriptionsIndex::class)->name('admin.subscriptions.index');
    });
