<?php

namespace App\Http\Middleware;

use App\Support\SiteText;
use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer pages in the language the customer chose (SiteText). Staff, provider, rider and seller panels stay
 * English; a Livewire update is judged by the page it came from, so an admin screen never turns Telugu.
 */
class SetCustomerLocale
{
    private const ENGLISH_ONLY = ['admin', 'admin/*', 'provider', 'provider/*', 'rider', 'rider/*', 'seller', 'seller/*', 'api/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $path = Livewire::isLivewireRequest() ? ltrim((string) Livewire::originalPath(), '/') : $request->path();
        $englishOnly = collect(self::ENGLISH_ONLY)->contains(fn ($p) => \Illuminate\Support\Str::is($p, $path));

        app()->setLocale($englishOnly ? 'en' : SiteText::localeFor($request->cookie(SiteText::COOKIE)));

        return $next($request);
    }
}
