<?php

namespace App\Http\Controllers;

use App\Services\Account\AccountDeletionService;
use App\Support\SiteText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * /delete-account (owner 2026-11-05, for Google Play): one public page with the steps for every 1CallFix app. Signed
 * in, the same page has the button: a customer closes their account (unless something blocks it), a partner sends a
 * request for an admin to finish. This is the URL to give in Play Console → Data safety → Delete account.
 */
class AccountDeletionController extends Controller
{
    public function __construct(private AccountDeletionService $deletion) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $user = $user && $user->status !== 'suspended' ? $user : null;

        return view('customer.delete-account', [
            'user' => $user,
            'partner' => $user ? $this->deletion->isPartner($user) : false,
            'blockers' => $user && ! $this->deletion->isPartner($user) ? $this->deletion->blockers($user) : [],
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => SiteText::get('account.delete.confirm_needed')]);

        if ($this->deletion->isPartner($user)) {
            $this->deletion->request($user);

            return redirect()->route('account.delete')->with('status', SiteText::get('account.delete.requested'));
        }

        if ($this->deletion->blockers($user) !== []) {
            return redirect()->route('account.delete');
        }

        $this->deletion->close($user, null, 'customer');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('account.delete')->with('status', SiteText::get('account.delete.done', ['days' => AccountDeletionService::ERASE_AFTER_DAYS]));
    }
}
