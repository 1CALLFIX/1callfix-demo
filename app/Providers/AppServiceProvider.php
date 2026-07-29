<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Franchise;
use App\Models\Zone;
use App\Observers\BookingObserver;
use App\Observers\FranchiseObserver;
use App\Observers\ZoneObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Booking::observe(BookingObserver::class);
        Franchise::observe(FranchiseObserver::class);
        Zone::observe(ZoneObserver::class);
    }
}