<?php

use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Bookings\Index as BookingsIndex;
use App\Livewire\Bookings\Show as BookingsShow;
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
    });
