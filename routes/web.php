<?php

use App\Http\Controllers\Customer\InvoiceController;
use App\Http\Controllers\Customer\PageController;
use App\Livewire\Customer\Account\Addresses as CustomerAddresses;
use App\Livewire\Customer\Auth\ForgotPassword as CustomerForgotPassword;
use App\Livewire\Customer\Auth\GoogleAuth as CustomerGoogleAuth;
use App\Livewire\Customer\Auth\Login as CustomerLogin;
use App\Livewire\Customer\Auth\PasswordMigration as CustomerPasswordMigration;
use App\Livewire\Customer\Auth\Signup as CustomerSignup;
use App\Livewire\Customer\Booking\Wizard as CustomerBookingWizard;
use App\Livewire\Customer\Bundles\Show as CustomerBundleShow;
use App\Livewire\Customer\Cart\Index as CustomerCart;
use App\Livewire\Customer\Catalog\CategoryIndex as CustomerCategoryIndex;
use App\Livewire\Customer\Checkout as CustomerCheckout;
use App\Livewire\Customer\Catalog\CategoryShow as CustomerCategoryShow;
use App\Livewire\Customer\Catalog\ServiceIndex as CustomerServiceIndex;
use App\Livewire\Customer\Catalog\ServiceShow as CustomerServiceShow;
use App\Livewire\Customer\Home as CustomerHome;
use App\Livewire\Customer\Orders\Show as CustomerOrderShow;
use App\Livewire\Customer\Orders\Index as CustomerOrders;
use App\Livewire\Customer\Search as CustomerSearch;
use App\Livewire\Customer\Wallet\Index as CustomerWallet;
use App\Livewire\Provider\Activity as ProviderActivity;
use App\Livewire\Provider\Auth\Login as ProviderLogin;
use App\Livewire\Provider\Dashboard as ProviderDashboard;
use App\Livewire\Provider\Earnings as ProviderEarnings;
use App\Livewire\Provider\History as ProviderHistory;
use App\Livewire\Provider\Jobs\Index as ProviderJobs;
use App\Livewire\Provider\Jobs\Show as ProviderJobShow;
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

/*
 | Auth (rebuild): password-first login, plus the one-time verification
 | flows — signup, forgot-password, the Google mandatory-mobile step, and
 | the migration path for pre-rebuild OTP-only accounts. All `guest`-only;
 | each screen redirects an already-authenticated customer home. OTP is no
 | longer a login route — see docs/auth-otp-consumer-audit.md.
 */
Route::middleware('guest')->group(function () {
    Route::get('/login', CustomerLogin::class)->name('customer.login');
    Route::get('/signup', CustomerSignup::class)->name('customer.signup');
    Route::get('/forgot-password', CustomerForgotPassword::class)->name('customer.password.forgot');
    Route::get('/auth/set-password', CustomerPasswordMigration::class)->name('customer.auth.migrate');
    Route::get('/auth/google', CustomerGoogleAuth::class)->name('customer.auth.google');
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

/*
 | Transactional customer web (Phase E6). Everything here needs a logged-in
 | customer session and is the completion of what routes/web.php's own
 | header comment called "Phases D–E ... NOT routed here yet": the booking
 | wizard, order history + live tracking, saved addresses, and the wallet.
 |
 | None of these components re-implement a booking, a price, a payment, a
 | cancellation, an OTP check, an invoice or a review — each one calls the
 | SAME Action/Service the existing REST API controllers already call
 | (CreateBookingAction, AdminCancelBookingAction, WalletService,
 | ReviewService, DocumentService, the PaymentGateway contract). The server
 | stays authoritative for every number and every state transition.
 */
Route::middleware('auth')->group(function () {
    Route::view('/account', 'customer.account')->name('customer.account');

    // Booking wizard — configure -> address -> schedule -> pay. Attaches to
    // the "Book now" button on a service's own Phase C detail page.
    Route::get('/book/{service}', CustomerBookingWizard::class)->name('customer.book');

    // Services cart -> checkout -> one BookingBundle. The cart groups its
    // lines into "visits" by subcategory; checkout hands the whole set to
    // CreateBookingBundleAction (no price ever from the client).
    Route::get('/cart', CustomerCart::class)->name('customer.cart');
    Route::get('/checkout', CustomerCheckout::class)->name('customer.checkout');
    Route::get('/bundles/{bundle}', CustomerBundleShow::class)->name('customer.bundles.show');

    // Order history + one order's live status / OTPs / invoice / review / rebook.
    Route::get('/orders', CustomerOrders::class)->name('customer.orders.index');
    Route::get('/orders/{booking}', CustomerOrderShow::class)->name('customer.orders.show');
    Route::get('/orders/{booking}/invoice', [InvoiceController::class, 'show'])->name('customer.orders.invoice');

    // Saved addresses (the same CRUD + delete-guard rules AddressController enforces).
    Route::get('/account/addresses', CustomerAddresses::class)->name('customer.addresses');

    // Wallet balance + ledger + top-up.
    Route::get('/wallet', CustomerWallet::class)->name('customer.wallet');
});

/*
 | Provider web (Phase PW1). A lightweight authenticated partner surface —
 | online/offline toggle, job offers, accept/decline, the start/completion
 | OTP flow, earnings, history and an activity feed. Shares the customer
 | `web` session (a person can be both); `/provider/*` is gated only on
 | "has a providers row" via EnsureIsProvider. Every state-changing call
 | reuses an existing engine — SetProviderOnlineStatusAction is the sole
 | new Action; accept/start/complete go straight to AcceptBookingAction /
 | StartBookingAction / CompleteBookingAction. See
 | PHASE_PW1_PROVIDER_WEB_P1_PLAN.md.
 */
Route::middleware('guest')->group(function () {
    Route::get('/provider/login', ProviderLogin::class)->name('provider.login');
});

Route::post('/provider/logout', function () {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('provider.login');
})->middleware('auth')->name('provider.logout');

Route::middleware(['auth', \App\Http\Middleware\EnsureIsProvider::class])
    ->prefix('provider')
    ->group(function () {
        Route::get('/', ProviderDashboard::class)->name('provider.dashboard');
        Route::get('/jobs', ProviderJobs::class)->name('provider.jobs.index');
        Route::get('/jobs/{booking}', ProviderJobShow::class)->name('provider.jobs.show');
        Route::get('/earnings', ProviderEarnings::class)->name('provider.earnings');
        Route::get('/history', ProviderHistory::class)->name('provider.history');
        Route::get('/activity', ProviderActivity::class)->name('provider.activity');
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
