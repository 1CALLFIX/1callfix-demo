<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Support\SiteText;
use Illuminate\Http\Request;

/** The top-bar language switch: remembers the choice on this device for a year, then back to the same page. */
class LanguageController extends Controller
{
    public function __invoke(Request $request, string $locale)
    {
        abort_unless(in_array($locale, SiteText::LOCALES, true), 404);

        return back()->withCookie(cookie()->forever(SiteText::COOKIE, $locale));
    }
}
