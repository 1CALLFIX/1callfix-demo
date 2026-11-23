<?php

namespace App\Http\Controllers;

use App\Models\FieldAssociate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * /fa/{code} (field associates, owner 2026-11-23): the link and QR a field associate shows at a shop. It remembers
 * the associate in the session and opens the partner page with "Seller or shop" picked; the shop or restaurant that
 * signs up next in this browser is credited to them (Provider\Auth\Register → RegisterSellerAction).
 *
 * Whoever is signed in on this phone is signed out first: the sign-up must make the shop owner's own account, never
 * add the shop to the associate's account or to the previous shop's owner (the sign-up signs the new owner in).
 * An unknown or paused code still opens the partner page, just without the credit.
 */
class FieldAssociateLinkController extends Controller
{
    public const SESSION_KEY = 'field_associate_id';

    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $associate = preg_match('/^[A-Za-z0-9]{2,12}$/', $code) === 1 ? FieldAssociate::findActiveByCode($code) : null;

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($associate) {
            $request->session()->put(self::SESSION_KEY, $associate->id);
        }

        return redirect()->to(route('customer.partners', ['role' => 'commerce']).'#formCard');
    }
}
