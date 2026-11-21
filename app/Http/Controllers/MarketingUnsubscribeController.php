<?php

namespace App\Http\Controllers;

use App\Models\MarketingContact;
use Illuminate\Http\Request;

/**
 * /unsubscribe/{token} for app invitation emails to imported contacts (owner 2026-11-21). The link only shows a button
 * (mail scanners that open links do not unsubscribe anyone); the button posts and records it. Unknown token: 404.
 */
class MarketingUnsubscribeController extends Controller
{
    public function show(string $token)
    {
        $contact = MarketingContact::where('unsubscribe_token', $token)->firstOrFail();

        return view('marketing.unsubscribe', ['token' => $token, 'done' => (bool) $contact->unsubscribed_at]);
    }

    public function store(Request $request, string $token)
    {
        $contact = MarketingContact::where('unsubscribe_token', $token)->firstOrFail();
        if (! $contact->unsubscribed_at) {
            $contact->forceFill(['unsubscribed_at' => now()])->save();
        }

        return view('marketing.unsubscribe', ['token' => $token, 'done' => true]);
    }
}
