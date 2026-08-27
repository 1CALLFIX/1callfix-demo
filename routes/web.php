<?php

use App\Http\Controllers\Customer\PageController;
use App\Livewire\Customer\Auth\Login as CustomerLogin;
use App\Livewire\Customer\Catalog\CategoryIndex as CustomerCategoryIndex;
use App\Livewire\Customer\Catalog\CategoryShow as CustomerCategoryShow;
use App\Livewire\Customer\Catalog\ServiceIndex as CustomerServiceIndex;
use App\Livewire\Customer\Catalog\ServiceShow as CustomerServiceShow;
use App\Livewire\Customer\Home as CustomerHome;
use App\Livewire\Customer\Search as CustomerSearch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer web application (Phase B — foundation & auth; Phase C — discovery)
|--------------------------------------------------------------------------
|
| The customer-facing web app. Everything here is named `customer.*` so it
| can never collide with the `admin.*` namespace in routes/admin.php, which
| is required (unchanged) at the bottom of this file.
|
| Phase B shipped the SHELL: layout, navigation, homepage foundation, OTP
| session authentication, location/zone context, PWA foundation, and the
| public/legal pages.
|
| Phase C adds DISCOVERY: the marketplace homepage, the category and
| subcategory explorer, the service catalog and detail screens with option
| configuration, search, and offers. Everything on those screens is read-only
| — nothing here creates, prices or commits a booking.
|
| The booking wizard, checkout, payment, wallet, membership and order
| tracking remain Phases D–E and are NOT routed here yet. Every navigation
| target that belongs to a later phase still points at
| `customer.coming-soon`, an honest labelled placeholder rather than a dead
| link or a fake screen. See CUSTOMER_WEBAPP_IMPLEMENTATION_PLAN.md.
|
| No route here duplicates or bypasses routes/api.php — the REST API keeps
| serving headless/mobile clients on Sanctum tokens exactly as before,
| while these routes run on the standard `web` session guard.
|
*/

Route::get('/', CustomerHome::class)->name('customer.home');

/*
 | Discovery & catalog (Phase C). All public and unauthenticated, matching
 | every other browse-first surface in this application: `GET /api/categories`,
 | `/api/subcategories` and `/api/services` are public routes for the same
 | reason — a customer browses before deciding to sign in.
 |
 | Route-model binding here resolves the ROW only. It knows nothing about
 | `is_active` or which vertical a category belongs to, so each component's
 | own mount() applies the catalog visibility rule and 404s otherwise —
 | see CategoryShow::mount() and ServiceShow::mount().
 |
 | Categories bind on `slug`, which carries a real unique index
 | (create_service_categories_table). Services bind on the primary key
 | instead: `services.slug` has NO unique constraint, so slug binding there
 | could silently resolve to whichever duplicate the database returned first.
 | Recorded as a schema gap in the Phase C report rather than papered over
 | with a "first match wins" lookup.
 */
Route::get('/search', CustomerSearch::class)->name('customer.search');
Route::get('/categories', CustomerCategoryIndex::class)->name('customer.categories.index');
Route::get('/categories/{category:slug}', CustomerCategoryShow::class)->name('customer.categories.show');
Route::get('/services', CustomerServiceIndex::class)->name('customer.services.index');
// Same component, narrowed to services carrying a live flash sale — see
// ServiceIndex::mount(), which reads this route's name.
Route::get('/offers', CustomerServiceIndex::class)->name('customer.offers');
Route::get('/services/{service}', CustomerServiceShow::class)->name('customer.services.show');

Route::middleware('guest')->group(function () {
    Route::get('/login', CustomerLogin::class)->name('customer.login');
});

/*
 | Session logout. POST-only + CSRF (a GET logout is CSRF-forgeable and
 | can be triggered by any third-party <img>/prefetch), and the full
 | invalidate + regenerateToken pair — the exact same shape as the
 | existing admin.logout route in routes/admin.php.
 */
Route::post('/logout', function () {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('customer.home');
})->middleware('auth')->name('customer.logout');

Route::middleware('auth')->group(function () {
    Route::view('/account', 'customer.account')->name('customer.account');
});

// Public content. `page` renders real seeded `content_pages` rows — the
// legal text itself is never authored or altered here (see
// database/seeders/LegalContentSeeder.php's own docblock).
Route::get('/help', [PageController::class, 'help'])->name('customer.help');
Route::get('/how-it-works', [PageController::class, 'howItWorks'])->name('customer.how-it-works');
Route::get('/privacy', [PageController::class, 'privacy'])->name('customer.privacy');
Route::get('/terms', [PageController::class, 'terms'])->name('customer.terms');

// Honest placeholder for every destination whose real screen lands in a
// later phase. Whitelisted to known feature keys so it can never render an
// arbitrary attacker-supplied label.
Route::get('/coming-soon/{feature}', [PageController::class, 'comingSoon'])
    ->whereIn('feature', PageController::COMING_SOON_FEATURES)
    ->name('customer.coming-soon');

require __DIR__.'/admin.php';
