<?php

namespace App\Http\Controllers;

use App\Models\FieldAssociate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /account/field-work/add/{kind} (field associates release A, owner 2026-11-24): "Sign up a shop now" on the
 * associate's own phone, without signing the associate out. It opens the partner sign-up in associate-assisted mode
 * (Provider\Auth\Register): the owner's own mobile gets the code and the owner reads it out, so the new account is the
 * owner's, never the associate's; the associate stays signed in; the owner uploads their own KYC later from their own
 * phone. The mode lives in the server session and only works for the signed-in, active associate.
 */
class FieldAssociateAssistController extends Controller
{
    public const SESSION_KEY = 'field_associate_assist';

    public function __invoke(Request $request, string $kind): RedirectResponse
    {
        $associate = FieldAssociate::query()->where('user_id', $request->user()->id)->first();
        abort_unless($associate !== null, 403, 'This page is for 1CallFix field associates.');
        if (! $associate->is_active) {
            return redirect()->route('customer.field-work');
        }

        $request->session()->forget(['partner_lead', FieldAssociateLinkController::SESSION_KEY]);
        $request->session()->put(self::SESSION_KEY, ['associate_id' => $associate->id, 'role' => $kind === 'restaurant' ? 'food' : 'commerce']);

        return redirect()->route('provider.register');
    }
}
