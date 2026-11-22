<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Marketing\MetaGraph;
use App\Support\SuperAdminGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Admin → Marketing → Social posts → "Connect Facebook & Instagram" (owner 2026-11-22). Super Admin only. Sends the
 * Super Admin to Facebook to approve once, and takes them back here. One Page → connected straight away; several →
 * they pick one on the Social posts screen (the choices wait 10 minutes, encrypted, for this user only).
 */
class MetaConnectController extends Controller
{
    public const CHOICES = 'marketing.meta.choices.';

    public function connect(Request $request, MetaGraph $meta): RedirectResponse
    {
        abort_unless(SuperAdminGate::allows($request->user()), 403, 'Only the Super Admin can connect Facebook.');
        // Opened on http:// or www.: move to the main address first, so the return address Facebook gets matches the
        // one saved in the Meta app and the login + state survive the round trip (same host both ways).
        if ($request->root() !== MetaGraph::mainRoot()) {
            return redirect()->away(MetaGraph::mainRoot().route('admin.marketing.meta.connect', [], false));
        }
        if (! $meta->hasApp()) {
            return redirect()->route('admin.marketing.posts')->with('meta_message', 'Type the Meta app ID and secret first.');
        }
        $state = Str::random(40);
        $request->session()->put('meta_oauth_state', $state);

        return redirect()->away($meta->loginUrl($state));
    }

    public function callback(Request $request, MetaGraph $meta): RedirectResponse
    {
        abort_unless(SuperAdminGate::allows($request->user()), 403, 'Only the Super Admin can connect Facebook.');
        $expected = (string) $request->session()->pull('meta_oauth_state', '');
        $back = redirect()->route('admin.marketing.posts');

        if ($request->filled('error')) {
            return $back->with('meta_message', 'Facebook connection cancelled: '.Str::limit((string) $request->query('error_description', $request->query('error')), 150));
        }
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state'))) {
            return $back->with('meta_message', 'That Facebook answer did not match. Press Connect again.');
        }

        try {
            $pages = $meta->pagesFromCode((string) $request->query('code'));
        } catch (\Throwable $e) {
            return $back->with('meta_message', 'Facebook connection failed: '.Str::limit($e->getMessage(), 200));
        }

        if ($pages === []) {
            return $back->with('meta_message', 'Facebook did not share any Page. Press Connect again and tick the 1CallFix Page.');
        }
        if (count($pages) === 1) {
            $meta->savePage($pages[0]);
            ActivityLogger::log($request->user(), 'setting', 0, 'Facebook Page connected for automatic posting', [
                'page' => $pages[0]['name'], 'instagram' => $pages[0]['ig_username'],
            ]);

            return $back->with('meta_message', 'Connected: '.$pages[0]['name'].($pages[0]['ig_username'] ? ' and Instagram @'.$pages[0]['ig_username'] : ' (no Instagram linked to this Page)').'.');
        }

        Cache::put(self::CHOICES.$request->user()->id, Crypt::encrypt($pages), now()->addMinutes(10));

        return $back->with('meta_message', 'Pick which Page to post to.');
    }
}
