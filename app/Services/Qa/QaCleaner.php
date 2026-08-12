<?php

namespace App\Services\Qa;

use App\Models\Address;
use App\Models\Banner;
use App\Models\Booking;
use App\Models\BookingExtraItem;
use App\Models\BookingStatusHistory;
use App\Models\BusinessAccount;
use App\Models\BusinessLocation;
use App\Models\City;
use App\Models\Commission;
use App\Models\ContentPage;
use App\Models\Country;
use App\Models\DispatchAttempt;
use App\Models\EntitlementBalance;
use App\Models\Faq;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\FieldWorkerDocument;
use App\Models\Franchise;
use App\Models\FranchiseModule;
use App\Models\LoyaltyPoint;
use App\Models\PartnerWorker;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Provider;
use App\Models\Referral;
use App\Models\RoleAssignment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * Deletes exactly what qa:seed created — driven by the QaManifest's
 * tracked root IDs (bookings, users, subscriptions, ...) plus DERIVED
 * lookups for the side-effect tables the real Actions/Services created
 * automatically (commissions, payments, wallet_transactions, wallets,
 * loyalty_points, notifications, booking_status_history, dispatch_attempts,
 * usage_ledger) — exact foreign-key derivation, not a naming-convention
 * guess, and not reliant on cascade-delete alone (several FKs in this
 * schema are nullOnDelete/restrictOnDelete, not cascadeOnDelete).
 *
 * Deletion order matters: children before parents, throughout.
 */
class QaCleaner
{
    /** @return array<string,int> counts actually deleted, per table */
    public function run(): array
    {
        if (! QaManifest::exists()) {
            throw new \RuntimeException('No QA seed manifest found — nothing to clean, or qa:seed was never run.');
        }

        $manifest = QaManifest::load();
        $entries = $manifest['entries'];
        $deleted = [];

        $bookingIds = $entries['bookings'] ?? [];
        $userIds = $entries['users'] ?? [];
        $subscriptionIds = $entries['subscriptions'] ?? [];

        DB::transaction(function () use ($entries, $bookingIds, $userIds, $subscriptionIds, &$deleted) {
            // --- Booking-derived side-effect tables (children first) ---
            $deleted['dispatch_attempts'] = DispatchAttempt::whereIn('booking_id', $bookingIds)->delete();
            $deleted['booking_status_history'] = BookingStatusHistory::whereIn('booking_id', $bookingIds)->delete();
            $deleted['booking_extra_items'] = BookingExtraItem::whereIn('booking_id', $bookingIds)->delete();
            $deleted['commissions'] = Commission::whereIn('booking_id', $bookingIds)->delete();
            $deleted['payments'] = Payment::whereIn('booking_id', $bookingIds)
                ->orWhereIn('id', $entries['payments'] ?? [])
                ->delete();

            // --- User-derived financial ledgers ---
            $walletIds = Wallet::whereIn('user_id', $userIds)->pluck('id');
            $deleted['wallet_transactions'] = WalletTransaction::whereIn('wallet_id', $walletIds)->delete();
            $deleted['wallets'] = Wallet::whereIn('user_id', $userIds)->delete();
            $deleted['loyalty_points'] = LoyaltyPoint::whereIn('user_id', $userIds)->delete();

            // --- Notifications (Laravel's standard notifiable morph, User only) ---
            $deleted['notifications'] = DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->whereIn('notifiable_id', $userIds)
                ->delete();

            // --- Referrals: tracked directly + derived backup for either side ---
            $referralIds = array_unique(array_merge(
                $entries['referrals'] ?? [],
                Referral::whereIn('referrer_id', $userIds)->orWhereIn('referred_id', $userIds)->pluck('id')->all()
            ));
            $deleted['referrals'] = Referral::whereIn('id', $referralIds)->delete();

            // --- Bookings themselves ---
            $deleted['bookings'] = Booking::whereIn('id', $bookingIds)->forceDelete();

            // --- Subscriptions / entitlements / usage ledger ---
            $entitlementBalanceIds = $entries['entitlement_balances'] ?? [];
            $deleted['usage_ledger'] = UsageLedger::whereIn('entitlement_balance_id', $entitlementBalanceIds)->delete();
            $deleted['entitlement_balances'] = EntitlementBalance::whereIn('id', $entitlementBalanceIds)
                ->orWhereIn('subscription_id', $subscriptionIds)
                ->delete();
            $deleted['subscriptions'] = Subscription::whereIn('id', $subscriptionIds)->delete();

            // --- Worker relationships ---
            $deleted['partner_workers'] = PartnerWorker::whereIn('id', $entries['partner_workers'] ?? [])->delete();
            $deleted['field_worker_capabilities'] = FieldWorkerCapability::whereIn('id', $entries['field_worker_capabilities'] ?? [])->delete();
            $deleted['field_worker_documents'] = FieldWorkerDocument::whereIn('id', $entries['field_worker_documents'] ?? [])->delete();
            $deleted['field_workers'] = FieldWorker::whereIn('id', $entries['field_workers'] ?? [])->forceDelete();

            // --- Providers ---
            $deleted['providers'] = Provider::whereIn('id', $entries['providers'] ?? [])->delete();

            // --- Business accounts ---
            $deleted['business_locations'] = BusinessLocation::whereIn('id', $entries['business_locations'] ?? [])->delete();
            $deleted['business_accounts'] = BusinessAccount::whereIn('id', $entries['business_accounts'] ?? [])->delete();

            // --- Addresses (customer + business) ---
            $deleted['addresses'] = Address::whereIn('id', $entries['addresses'] ?? [])->delete();

            // --- RBAC assignments ---
            $deleted['role_assignments'] = RoleAssignment::whereIn('id', $entries['role_assignments'] ?? [])->delete();

            // --- Users (last of the user-rooted chain) ---
            $deleted['users'] = User::whereIn('id', $userIds)->forceDelete();

            // --- Marketing/content ---
            $deleted['banners'] = Banner::whereIn('id', $entries['banners'] ?? [])->delete();
            $deleted['content_pages'] = ContentPage::whereIn('id', $entries['content_pages'] ?? [])->delete();
            $deleted['faqs'] = Faq::whereIn('id', $entries['faqs'] ?? [])->delete();

            // --- Plans ---
            $deleted['plan_entitlements'] = PlanEntitlement::whereIn('id', $entries['plan_entitlements'] ?? [])->delete();
            $deleted['plans'] = Plan::whereIn('id', $entries['plans'] ?? [])->forceDelete();

            // --- Catalog ---
            $deleted['services'] = Service::whereIn('id', $entries['services'] ?? [])->forceDelete();
            $deleted['service_subcategories'] = ServiceSubcategory::whereIn('id', $entries['service_subcategories'] ?? [])->delete();
            $deleted['service_categories'] = ServiceCategory::whereIn('id', $entries['service_categories'] ?? [])->delete();

            // --- Geography (deepest-nested first) ---
            $deleted['franchise_modules'] = FranchiseModule::whereIn('id', $entries['franchise_modules'] ?? [])->delete();
            $deleted['zones'] = Zone::whereIn('id', $entries['zones'] ?? [])->delete();
            $deleted['franchises'] = Franchise::whereIn('id', $entries['franchises'] ?? [])->forceDelete();
            $deleted['cities'] = City::whereIn('id', $entries['cities'] ?? [])->delete();
            $deleted['countries'] = Country::whereIn('id', $entries['countries'] ?? [])->delete();
        });

        QaManifest::delete();

        return $deleted;
    }
}
