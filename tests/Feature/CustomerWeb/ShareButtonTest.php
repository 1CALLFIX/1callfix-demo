<?php

namespace Tests\Feature\CustomerWeb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Owner 2026-11-06: every module, product, item, service, food, hotel room and so on has a Share option. The
 * button opens the phone's share sheet inside the Android apps (Capacitor Share plugin) or in a phone browser,
 * and otherwise offers WhatsApp and Copy link. Every public customer page must carry it, including the pages of
 * modules not built yet (any new folder under livewire/customer is public unless listed below as private).
 */
class ShareButtonTest extends TestCase
{
    use RefreshDatabase;

    /** Signed-in or step-by-step pages with nothing to share (carts, checkouts, orders, sign-in, account). */
    private const PRIVATE = [
        'account/', 'auth/', 'booking/', 'bundles/', 'cart/', 'earnings/', 'orders/',
        'membership/account', 'parcel/index', 'parcel/show',
        'shop/bag', 'shop/checkout', 'shop/order-show', 'shop/orders',
        'hotels/book', 'hotels/booking',
        'events/book', 'events/order', 'events/tickets', 'halls/book', 'halls/booking', 'halls/bookings',
        'checkout', 'home', 'search',
    ];

    public function test_every_public_customer_page_has_a_share_button(): void
    {
        $root = resource_path('views/livewire/customer');
        $missing = [];
        foreach (File::allFiles($root) as $file) {
            $view = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1, -strlen('.blade.php')));
            $html = $file->getContents();
            if (! str_contains($html, '<h1') || $this->isPrivate($view)) {
                continue;
            }
            if (! str_contains($html, '<x-customer.share-button') && ! str_contains($html, '<x-customer.item-actions')) {
                $missing[] = $view;
            }
        }

        $this->assertSame([], $missing, 'Public pages without a Share button (add <x-customer.share-button>, or <x-customer.item-actions> on an item photo).');
    }

    public function test_the_button_tries_the_app_share_sheet_then_the_browser_one_then_offers_whatsapp_and_copy(): void
    {
        $html = Blade::render('<x-customer.share-button title="AC Service" />');

        $this->assertStringContainsString('data-share-button', $html);
        $this->assertStringContainsString("isPluginAvailable('Share')", $html);
        $this->assertStringContainsString('navigator.share', $html);
        $this->assertStringContainsString('https://wa.me/?text=', $html);
        $this->assertStringContainsString('>WhatsApp<', $html);
        $this->assertStringContainsString('Copy link', $html);
        $this->assertStringContainsString('Check out AC Service on 1CallFix', $html);
        // The link is the page itself without its query string (city and slug kept, no tracking passed on).
        $this->assertStringContainsString('window.location.origin + window.location.pathname', $html);
    }

    public function test_a_small_icon_shares_one_item_and_links_straight_to_it(): void
    {
        $html = Blade::render('<x-customer.share-button compact title="Veg Meals" hash="dish-7" />');

        $this->assertStringContainsString('aria-label="Share Veg Meals"', $html);
        $this->assertStringContainsString('#dish-7', $html);
        $this->assertStringNotContainsString('>Share<', $html);
    }

    public function test_the_apps_carry_the_share_plugin(): void
    {
        $package = json_decode(File::get(base_path('mobile/package.json')), true);

        $this->assertArrayHasKey('@capacitor/share', $package['dependencies']);
    }

    private function isPrivate(string $view): bool
    {
        foreach (self::PRIVATE as $p) {
            if (str_ends_with($p, '/') ? str_starts_with($view, $p) : $view === $p) {
                return true;
            }
        }

        return false;
    }
}
