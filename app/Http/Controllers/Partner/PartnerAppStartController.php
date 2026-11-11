<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Start page of the "1CallFix Partner" Android app (mobile/, package com.onecall.vendor). One app for service
 * partners and shops/restaurants: a signed-in partner goes straight to their own app; anyone else picks how to
 * sign in, or joins through /partners (the one way partners join).
 */
class PartnerAppStartController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|View
    {
        $user = $request->user();

        if ($user) {
            foreach (['service' => 'provider.dashboard', 'commerce' => 'seller.dashboard', 'hotel' => 'hotel.dashboard', 'parcel' => 'rider.dashboard'] as $role => $route) {
                if ($user->holdsPartnerRole($role)) {
                    return redirect()->route($route);
                }
            }

            return redirect()->route('customer.partners');
        }

        return view('partner.app.start');
    }
}
