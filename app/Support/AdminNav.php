<?php

namespace App\Support;

use App\Models\User;

/**
 * The admin side menu, one place (owner request 2026-11-03): grouped by section and by module, so every module's
 * orders, catalogue, money screens and settings sit together, and anyone can find "where is that setting".
 * The dashboard's module tabs reuse the same groups as their quick links.
 *
 * Each item's 'permission' is the SAME slug (or any-of list) its target screen's own mount() enforces. This only
 * hides links a user cannot open anyway; each screen's own check is still the real boundary. 'params' adds a
 * query string (e.g. Stores filtered to restaurants). Groups marked 'prelaunch' start collapsed and muted.
 */
class AdminNav
{
    public static function groups(): array
    {
        return [
            'overview' => [
                'label' => 'Overview', 'collapsible' => false, 'icon' => null, 'colour' => 'slate',
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'home', 'permission' => 'dashboard.view'],
                ],
            ],
            // Every person directory and partner sign-up under one umbrella.
            'users' => [
                'label' => 'Users & Partners', 'collapsible' => true, 'icon' => 'users', 'colour' => 'sky',
                'items' => [
                    ['label' => 'All Users', 'route' => 'admin.all-users.index', 'icon' => 'users', 'permission' => 'users.directory.view'],
                    ['label' => 'Customers', 'route' => 'admin.customers.index', 'icon' => 'users', 'permission' => 'customers.view'],
                    ['label' => 'Account deletions', 'route' => 'admin.account-deletions', 'icon' => 'users', 'permission' => 'users.directory.view'],
                    ['label' => 'Providers', 'route' => 'admin.providers.index', 'icon' => 'users', 'permission' => 'providers.view'],
                    // Partners (2026-10-26) replaces Workers and the empty Drivers slot: People + Businesses tabs.
                    ['label' => 'Partners', 'route' => 'admin.partners.index', 'icon' => 'users', 'permission' => ['workers.view', 'providers.view']],
                    ['label' => 'KYC Support Requests', 'route' => 'admin.kyc.support-requests.index', 'icon' => 'shield', 'permission' => ['kyc.support_requests.create', 'kyc.support_requests.decide']],
                    ['label' => 'Partner leads', 'route' => 'admin.partner-leads.index', 'icon' => 'users', 'permission' => 'partner_page.manage'],
                ],
            ],
            // ---- Modules: each one's orders, catalogue, money and settings together ----
            'services' => [
                'label' => 'Services', 'collapsible' => true, 'icon' => 'wrench', 'colour' => 'blue', 'module' => 'service',
                'items' => [
                    ['label' => 'Bookings', 'route' => 'admin.bookings.index', 'icon' => 'clipboard', 'permission' => 'bookings.view'],
                    ['label' => 'Services', 'route' => 'admin.services.index', 'icon' => 'wrench', 'permission' => 'services.manage'],
                    ['label' => 'Categories', 'route' => 'admin.categories.index', 'icon' => 'wrench', 'permission' => 'categories.manage'],
                    ['label' => 'Subcategories', 'route' => 'admin.subcategories.index', 'icon' => 'wrench', 'permission' => 'categories.manage'],
                    // super_admin only (permission never seeded to a role).
                    ['label' => 'Availability & Shifts', 'route' => 'admin.providers.availability', 'icon' => 'clock', 'permission' => 'provider_availability.manage'],
                    ['label' => 'Pricing Disputes', 'route' => 'admin.booking-disputes.index', 'icon' => 'banknotes', 'permission' => 'bookings.refund_dispute'],
                    ['label' => 'Charge Waivers', 'route' => 'admin.charge-waivers.index', 'icon' => 'banknotes', 'permission' => 'bookings.waive_charge'],
                    // super_admin only (permission never seeded to a role).
                    ['label' => 'Cancellation Policy', 'route' => 'admin.cancellation-policy.index', 'icon' => 'gear', 'permission' => 'cancellation_policy.manage'],
                ],
            ],
            'parcel' => [
                'label' => 'Parcel', 'collapsible' => true, 'icon' => 'shopping-bag', 'colour' => 'orange', 'module' => 'parcel',
                'items' => [
                    ['label' => 'Parcel Dashboard', 'route' => 'admin.parcel-orders.dashboard', 'icon' => 'chart-bar', 'permission' => 'parcel_orders.view'],
                    ['label' => 'Parcel Orders', 'route' => 'admin.parcel-orders.index', 'icon' => 'clipboard', 'permission' => 'parcel_orders.view'],
                    ['label' => 'Parcel Claims', 'route' => 'admin.parcel-claims.index', 'icon' => 'banknotes', 'permission' => 'parcel_claims.manage'],
                    ['label' => 'Parcel Packs', 'route' => 'admin.parcel-packs.index', 'icon' => 'tag', 'permission' => 'parcel_settings.manage'],
                    // super_admin only (permission never seeded to a role).
                    ['label' => 'Parcel Settings', 'route' => 'admin.parcel-orders.settings', 'icon' => 'gear', 'permission' => 'parcel_settings.manage'],
                ],
            ],
            'shop' => [
                'label' => 'Shop', 'collapsible' => true, 'icon' => 'shopping-cart', 'colour' => 'violet', 'module' => 'shop',
                'items' => [
                    ['label' => 'Shop Orders', 'route' => 'admin.marketplace-orders.index', 'params' => ['moduleFilter' => 'shop'], 'icon' => 'clipboard', 'permission' => 'marketplace_orders.view'],
                    ['label' => 'Stores', 'route' => 'admin.stores.index', 'icon' => 'building', 'permission' => 'stores.manage'],
                    ['label' => 'Products', 'route' => 'admin.products.index', 'icon' => 'wrench', 'permission' => 'products.manage'],
                    ['label' => 'Marketplace Categories', 'route' => 'admin.marketplace-categories.index', 'icon' => 'wrench', 'permission' => 'marketplace_categories.manage'],
                    ['label' => 'Changes to approve', 'route' => 'admin.change-approvals.index', 'icon' => 'check-circle', 'permission' => 'products.manage'],
                    ['label' => 'Returns', 'route' => 'admin.shop-returns.index', 'icon' => 'banknotes', 'permission' => 'marketplace_returns.manage'],
                    ['label' => 'Shop Commission', 'route' => 'admin.shop-commission.index', 'icon' => 'banknotes', 'permission' => 'marketplace_commission.manage'],
                    // super_admin only (permission never seeded to a role).
                    ['label' => 'Shop Tax Report', 'route' => 'admin.shop-tax-report', 'icon' => 'chart-bar', 'permission' => 'marketplace_tax_report.view'],
                    // super_admin only (permission never seeded to a role).
                    ['label' => 'Shop Settings', 'route' => 'admin.marketplace-orders.settings', 'icon' => 'gear', 'permission' => 'marketplace_settings.manage'],
                ],
            ],
            // Food runs on the shop engine (same stores/products/orders tables), filtered to the food module.
            'food' => [
                'label' => 'Food', 'collapsible' => true, 'icon' => 'sparkles', 'colour' => 'rose', 'module' => 'food',
                'items' => [
                    ['label' => 'Food Dashboard', 'route' => 'admin.food.dashboard', 'icon' => 'chart-bar', 'permission' => 'marketplace_orders.view'],
                    ['label' => 'Food Orders', 'route' => 'admin.marketplace-orders.index', 'params' => ['moduleFilter' => 'food'], 'icon' => 'clipboard', 'permission' => 'marketplace_orders.view'],
                    ['label' => 'Restaurants', 'route' => 'admin.stores.index', 'params' => ['moduleFilter' => 'food'], 'icon' => 'building', 'permission' => 'stores.manage'],
                    ['label' => 'Add-Ons', 'route' => 'admin.add-ons.index', 'icon' => 'wrench', 'permission' => 'products.manage'],
                    ['label' => 'Food problems', 'route' => 'admin.shop-returns.index', 'params' => ['statusFilter' => 'food'], 'icon' => 'banknotes', 'permission' => 'marketplace_returns.manage'],
                    // Same Super-Admin-only permission as Shop Settings (one engine).
                    ['label' => 'Food Settings', 'route' => 'admin.food.settings', 'icon' => 'gear', 'permission' => 'marketplace_settings.manage'],
                ],
            ],
            'taxi' => [
                'label' => 'Taxi', 'collapsible' => true, 'icon' => 'map-pin', 'colour' => 'amber', 'module' => 'taxi', 'preLaunch' => true,
                'items' => [
                    ['label' => 'Taxi Rides', 'route' => 'admin.taxi-rides.index', 'icon' => 'clipboard', 'permission' => 'taxi_rides.view'],
                ],
            ],
            'rental' => [
                'label' => 'Rental', 'collapsible' => true, 'icon' => 'building', 'colour' => 'teal', 'module' => 'rental', 'preLaunch' => true,
                'items' => [
                    ['label' => 'Rental Reservations', 'route' => 'admin.rental-reservations.index', 'icon' => 'clipboard', 'permission' => 'rental_reservations.view'],
                    ['label' => 'Vehicles', 'route' => 'admin.vehicles.index', 'icon' => 'building', 'permission' => 'vehicles.manage'],
                    ['label' => 'Equipment', 'route' => 'admin.equipment.index', 'icon' => 'wrench', 'permission' => 'equipment.manage'],
                    ['label' => 'Properties', 'route' => 'admin.properties.index', 'icon' => 'building', 'permission' => 'properties.manage'],
                    ['label' => 'Property Reservations', 'route' => 'admin.property-reservations.index', 'icon' => 'clipboard', 'permission' => 'property_reservations.view'],
                ],
            ],
            'hotel' => [
                'label' => 'Hotel', 'collapsible' => true, 'icon' => 'building', 'colour' => 'cyan', 'module' => 'hotel', 'preLaunch' => true,
                'items' => [
                    ['label' => 'Hotel Reservations', 'route' => 'admin.hotel-reservations.index', 'icon' => 'clipboard', 'permission' => 'hotel_reservations.view'],
                    ['label' => 'Accommodations', 'route' => 'admin.accommodations.index', 'icon' => 'building', 'permission' => 'accommodations.manage'],
                ],
            ],
            // ---- Shared across every module ----
            'marketing' => [
                'label' => 'Marketing & Offers', 'collapsible' => true, 'icon' => 'megaphone', 'colour' => 'pink',
                'items' => [
                    ['label' => 'Coupons', 'route' => 'admin.coupons.index', 'icon' => 'ticket', 'permission' => 'coupons.view'],
                    ['label' => 'Flash Sales', 'route' => 'admin.flash-sales.index', 'icon' => 'bolt', 'permission' => 'flash_sales.view'],
                    ['label' => 'Banners', 'route' => 'admin.banners.index', 'icon' => 'megaphone', 'permission' => 'banners.manage'],
                    ['label' => 'Home Spotlight', 'route' => 'admin.home-spotlight.index', 'icon' => 'sparkles', 'permission' => 'banners.manage'],
                    ['label' => 'Badges', 'route' => 'admin.badges.index', 'icon' => 'tag', 'permission' => 'badges.view'],
                    ['label' => 'Performance Campaigns', 'route' => 'admin.performance-campaigns.index', 'icon' => 'trophy', 'permission' => 'performance_campaigns.view'],
                    ['label' => 'Google Reviews', 'route' => 'admin.reviews.google', 'icon' => 'star', 'permission' => 'google_reviews.manage'],
                ],
            ],
            // Owner 2026-11-03: wallet, loyalty, referrals and memberships in one place.
            'rewards' => [
                'label' => 'Wallet & Rewards', 'collapsible' => true, 'icon' => 'wallet', 'colour' => 'yellow',
                'items' => [
                    ['label' => 'Wallet Ledger', 'route' => 'admin.wallet-ledger.index', 'icon' => 'wallet', 'permission' => 'wallets.view'],
                    ['label' => 'Loyalty & Referrals', 'route' => 'admin.loyalty.index', 'icon' => 'sparkles', 'permission' => 'loyalty.view'],
                    ['label' => 'Plans & Memberships', 'route' => 'admin.plans.index', 'icon' => 'star', 'permission' => 'plans.view'],
                    ['label' => 'Subscriptions', 'route' => 'admin.subscriptions.index', 'icon' => 'ticket', 'permission' => 'subscriptions.view'],
                    // super_admin only (permission never seeded to a role): the wallet / loyalty / referral switches,
                    // freeze and adjustments.
                    ['label' => 'Earnings Control', 'route' => 'admin.earnings-control.index', 'icon' => 'shield', 'permission' => 'earnings_control.manage'],
                ],
            ],
            // Website pages and their upkeep: homepage, words, pages (CMS: new pages and their web addresses),
            // search & social, city page content. Item slugs are edited on each item's own screen.
            'website' => [
                'label' => 'Website & Pages', 'collapsible' => true, 'icon' => 'document-text', 'colour' => 'indigo',
                'items' => [
                    ['label' => 'Homepage & sign-up', 'route' => 'admin.site-controls', 'icon' => 'home', 'permission' => 'site_controls.manage'],
                    ['label' => 'Homepage sections', 'route' => 'admin.home-sections', 'icon' => 'home', 'permission' => 'site_controls.manage'],
                    ['label' => 'Website text & language', 'route' => 'admin.site-text', 'icon' => 'document-text', 'permission' => 'site_controls.manage'],
                    ['label' => 'Search Box', 'route' => 'admin.search-box.index', 'icon' => 'magnifying-glass', 'permission' => 'banners.manage'],
                    ['label' => 'Partner page', 'route' => 'admin.partner-page.settings', 'icon' => 'users', 'permission' => 'partner_page.manage'],
                    // super_admin only (permission never seeded to a role; each screen also checks the role itself).
                    ['label' => 'Franchise page', 'route' => 'admin.franchise-page', 'icon' => 'building', 'permission' => 'franchise_page.manage'],
                    ['label' => 'Contact page', 'route' => 'admin.contact-page', 'icon' => 'phone', 'permission' => 'contact_page.manage'],
                    ['label' => 'Website / CMS', 'route' => 'admin.cms.index', 'icon' => 'document', 'permission' => 'cms.manage'],
                    ['label' => 'Search & Social', 'route' => 'admin.seo.settings', 'icon' => 'magnifying-glass', 'permission' => 'seo.manage_global'],
                    ['label' => 'City Page Content', 'route' => 'admin.seo.city-content', 'icon' => 'map', 'permission' => 'seo.edit_city_content'],
                ],
            ],
            'finance' => [
                'label' => 'Finance', 'collapsible' => true, 'icon' => 'banknotes', 'colour' => 'emerald',
                'items' => [
                    ['label' => 'Payments', 'route' => 'admin.payments.index', 'icon' => 'banknotes', 'permission' => 'payments.view'],
                    ['label' => 'Payouts', 'route' => 'admin.payouts.index', 'icon' => 'banknotes', 'permission' => 'payouts.manage'],
                    ['label' => 'Commissions', 'route' => 'admin.commissions.index', 'icon' => 'banknotes', 'permission' => 'commissions.view'],
                    ['label' => 'Franchise Earnings', 'route' => 'admin.franchise-earnings.index', 'icon' => 'banknotes', 'permission' => 'commissions.view'],
                    ['label' => 'Mismatch Refunds', 'route' => 'admin.mismatch-refunds.index', 'icon' => 'banknotes', 'permission' => 'payments.refund_mismatch'],
                    // super_admin only (permission never seeded to a role).
                    ['label' => 'Refund Controls', 'route' => 'admin.refund-controls.index', 'icon' => 'shield', 'permission' => 'refund_controls.manage'],
                    ['label' => 'Payment Gateways', 'route' => 'admin.payment-gateways.index', 'icon' => 'shield', 'permission' => 'payment_gateways.manage'],
                ],
            ],
            'geography' => [
                'label' => 'Cities & Franchises', 'collapsible' => true, 'icon' => 'map', 'colour' => 'lime',
                'items' => [
                    ['label' => 'Countries & Cities', 'route' => 'admin.geography.index', 'icon' => 'map', 'permission' => 'geography.manage'],
                    ['label' => 'Franchises', 'route' => 'admin.franchises.index', 'icon' => 'building', 'permission' => 'franchises.manage'],
                    ['label' => 'Zones', 'route' => 'admin.zones.index', 'icon' => 'map-pin', 'permission' => 'zones.manage'],
                ],
            ],
            'communication' => [
                'label' => 'Messages & Alerts', 'collapsible' => true, 'icon' => 'chat', 'colour' => 'fuchsia',
                'items' => [
                    ['label' => 'Chat', 'route' => 'admin.chat.index', 'icon' => 'chat', 'permission' => 'chat.view'],
                    ['label' => 'Notification Center', 'route' => 'admin.notifications.index', 'icon' => 'bell', 'permission' => 'notification.view'],
                    // super_admin only (permission never seeded to a role).
                    ['label' => 'Alert Emails', 'route' => 'admin.alert-emails.index', 'icon' => 'activity', 'permission' => 'alert_emails.manage'],
                ],
            ],
            'system' => [
                'label' => 'Settings & System', 'collapsible' => true, 'icon' => 'gear', 'colour' => 'gray',
                'items' => [
                    ['label' => 'Settings', 'route' => 'admin.settings.index', 'icon' => 'gear', 'permission' => 'settings.manage'],
                    ['label' => 'Modules (on / off)', 'route' => 'admin.modules.index', 'icon' => 'gear', 'permission' => 'modules.manage'],
                    ['label' => 'Roles & Permissions', 'route' => 'admin.roles.index', 'icon' => 'shield', 'permission' => 'roles.manage'],
                    ['label' => 'Operations', 'route' => 'admin.operations.index', 'icon' => 'activity', 'permission' => 'operations.view'],
                    ['label' => 'Backup & Restore', 'route' => 'admin.operations.backup-restore', 'icon' => 'shield', 'permission' => 'backups.restore'],
                    // Test/staging tool only: the screen itself 404s in production, so the link is hidden there.
                    ['label' => 'Clear Data', 'route' => 'admin.operations.data-clear', 'icon' => 'exclamation-triangle', 'permission' => 'operations.data_clear', 'hide_in_production' => true],
                    ['label' => 'Security Headers', 'route' => 'admin.security.headers', 'icon' => 'shield', 'permission' => 'security_headers.manage'],
                ],
            ],
        ];
    }

    /**
     * Groups and items this user can open, each item with its 'url' and 'current' flag, plus the active group key.
     *
     * @return array{0: array, 1: ?string}
     */
    public static function forUser(User $user): array
    {
        $canSee = fn ($item) => ! (($item['hide_in_production'] ?? false) && app()->environment('production'))
            && collect((array) $item['permission'])->contains(fn ($p) => $user->hasPermissionAnywhere($p));

        $groups = [];
        $active = null;
        foreach (self::groups() as $key => $group) {
            $items = array_values(array_filter($group['items'], $canSee));
            if (empty($items)) {
                continue;
            }
            foreach ($items as $i => $item) {
                $items[$i]['url'] = route($item['route'], $item['params'] ?? []);
                $items[$i]['current'] = self::isCurrent($item);
                // Red dot on Partners while anyone in the user's area waits past the Overdue hours (2026-10-27).
                if ($item['route'] === 'admin.partners.index') {
                    $items[$i]['alert'] = PartnerReview::overdueFor($user);
                }
            }
            $group['items'] = $items;
            $groups[$key] = $group;

            if ($active === null && collect($items)->contains('current', true)) {
                $active = $key;
            }
        }

        return [$groups, $active];
    }

    /**
     * Current when the route matches and, for an item with 'params', the query string matches too. A plain item
     * (Stores) is not current while a filtered sibling (Restaurants: Stores ?moduleFilter=food) is the one open.
     */
    private static function isCurrent(array $item): bool
    {
        if (! request()->routeIs($item['route'].'*')) {
            return false;
        }
        if (! empty($item['params'])) {
            return collect($item['params'])->every(fn ($v, $k) => (string) request()->query($k) === (string) $v);
        }

        $filtered = collect(self::groups())->flatMap(fn ($g) => $g['items'])
            ->filter(fn ($other) => $other['route'] === $item['route'] && ! empty($other['params']));

        return ! $filtered->contains(fn ($other) => collect($other['params'])->every(fn ($v, $k) => (string) request()->query($k) === (string) $v));
    }

    /** Tailwind colour classes per group colour (written out in full so the Tailwind CDN sees them). */
    public static function tone(string $colour): array
    {
        return [
            'slate' => ['dot' => 'text-slate-300', 'bar' => 'bg-slate-400', 'chip' => 'bg-slate-100 text-slate-700 border-slate-200', 'solid' => 'bg-slate-700 text-white'],
            'sky' => ['dot' => 'text-sky-300', 'bar' => 'bg-sky-400', 'chip' => 'bg-sky-50 text-sky-700 border-sky-200', 'solid' => 'bg-sky-600 text-white'],
            'blue' => ['dot' => 'text-blue-300', 'bar' => 'bg-blue-500', 'chip' => 'bg-blue-50 text-blue-700 border-blue-200', 'solid' => 'bg-blue-600 text-white'],
            'orange' => ['dot' => 'text-orange-300', 'bar' => 'bg-orange-500', 'chip' => 'bg-orange-50 text-orange-700 border-orange-200', 'solid' => 'bg-orange-500 text-white'],
            'violet' => ['dot' => 'text-violet-300', 'bar' => 'bg-violet-500', 'chip' => 'bg-violet-50 text-violet-700 border-violet-200', 'solid' => 'bg-violet-600 text-white'],
            'rose' => ['dot' => 'text-rose-300', 'bar' => 'bg-rose-500', 'chip' => 'bg-rose-50 text-rose-700 border-rose-200', 'solid' => 'bg-rose-600 text-white'],
            'amber' => ['dot' => 'text-amber-300', 'bar' => 'bg-amber-400', 'chip' => 'bg-amber-50 text-amber-800 border-amber-200', 'solid' => 'bg-amber-500 text-white'],
            'teal' => ['dot' => 'text-teal-300', 'bar' => 'bg-teal-500', 'chip' => 'bg-teal-50 text-teal-700 border-teal-200', 'solid' => 'bg-teal-600 text-white'],
            'cyan' => ['dot' => 'text-cyan-300', 'bar' => 'bg-cyan-500', 'chip' => 'bg-cyan-50 text-cyan-700 border-cyan-200', 'solid' => 'bg-cyan-600 text-white'],
            'pink' => ['dot' => 'text-pink-300', 'bar' => 'bg-pink-500', 'chip' => 'bg-pink-50 text-pink-700 border-pink-200', 'solid' => 'bg-pink-600 text-white'],
            'indigo' => ['dot' => 'text-indigo-300', 'bar' => 'bg-indigo-500', 'chip' => 'bg-indigo-50 text-indigo-700 border-indigo-200', 'solid' => 'bg-indigo-600 text-white'],
            'emerald' => ['dot' => 'text-emerald-300', 'bar' => 'bg-emerald-500', 'chip' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'solid' => 'bg-emerald-600 text-white'],
            'yellow' => ['dot' => 'text-yellow-300', 'bar' => 'bg-yellow-400', 'chip' => 'bg-yellow-50 text-yellow-800 border-yellow-200', 'solid' => 'bg-yellow-500 text-white'],
            'lime' => ['dot' => 'text-lime-300', 'bar' => 'bg-lime-500', 'chip' => 'bg-lime-50 text-lime-800 border-lime-200', 'solid' => 'bg-lime-600 text-white'],
            'fuchsia' => ['dot' => 'text-fuchsia-300', 'bar' => 'bg-fuchsia-500', 'chip' => 'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-200', 'solid' => 'bg-fuchsia-600 text-white'],
            'gray' => ['dot' => 'text-gray-300', 'bar' => 'bg-gray-400', 'chip' => 'bg-gray-100 text-gray-700 border-gray-200', 'solid' => 'bg-gray-700 text-white'],
        ][$colour] ?? ['dot' => 'text-gray-300', 'bar' => 'bg-gray-400', 'chip' => 'bg-gray-100 text-gray-700 border-gray-200', 'solid' => 'bg-gray-700 text-white'];
    }
}
