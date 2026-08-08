<?php

use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Bookings\Index as BookingsIndex;
use App\Livewire\Bookings\Show as BookingsShow;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\Providers\Show as ProvidersShow;
use App\Livewire\Franchises\Index as FranchisesIndex;
use App\Livewire\Franchises\Form as FranchisesForm;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/admin/login', Login::class)->name('admin.login');

Route::post('/admin/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('admin.login');
})->middleware('auth')->name('admin.logout');

Route::middleware(['auth', \App\Http\Middleware\EnsureSuperAdmin::class])
    ->prefix('admin')
    ->group(function () {
        Route::get('/dashboard', Dashboard::class)->name('admin.dashboard');
        Route::get('/bookings', BookingsIndex::class)->name('admin.bookings.index');
        Route::get('/bookings/{bookingId}', BookingsShow::class)->name('admin.bookings.show');
        Route::get('/providers', ProvidersIndex::class)->name('admin.providers.index');
        Route::get('/providers/{providerId}', ProvidersShow::class)->name('admin.providers.show');
        Route::get('/franchises', FranchisesIndex::class)->name('admin.franchises.index');
        Route::get('/franchises/create', FranchisesForm::class)->name('admin.franchises.create');
        Route::get('/franchises/{franchiseId}/edit', FranchisesForm::class)->name('admin.franchises.edit');
    });
