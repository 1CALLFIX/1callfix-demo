<?php

namespace App\Services\Operations;

/**
 * Operations "Clear Data" tool — the single source of truth for what CAN be
 * offered as a clearable module and what NEVER can, read by both the
 * service and the admin screen so the two can never disagree.
 *
 * Deliberately a small, hand-verified catalog, not a sweep of every table
 * in the schema. Each module's table list was checked against its real
 * migration (FK direction, cascadeOnDelete behavior) before being added
 * here — see each module's own comment. Growing this catalog is the
 * intended way to extend the tool's coverage over time, one verified
 * module at a time; adding an unverified table here would undermine the
 * whole point of this being the highest-stakes tool in the project.
 *
 * Notably NOT included yet: a "Customers" module. users.status distinguishes
 * customer/provider/staff by role, not by table, so clearing "customers"
 * safely means deleting only users where role = 'customer' — a row-level
 * filter, not a table-level one like every module below. That's a real,
 * different shape of danger (a bug in the filter clears staff accounts,
 * not just an extra table) deserving its own deliberate design and
 * sign-off, not a rushed inclusion here.
 */
class DataClearCatalog
{
    public const MODULES = [
        'bookings' => [
            'label' => 'Bookings (Service)',
            'description' => 'Service bookings and everything that only exists because of one: chat, reviews, compensations, dispatch attempts, cash-commission receivables.',
            // Children listed BEFORE their parent `bookings` -- explicit,
            // auditable deletion order, the same "reverse order, don't
            // rely on cascade-delete alone" discipline
            // App\Services\Qa\QaManifest already established for qa:clean
            // (its own docblock: "rather than relying on cascade-delete
            // alone, so cleanup is exact and auditable"). Verified against
            // each table's real migration: chat_messages.booking_id,
            // reviews.booking_id, booking_compensations.booking_id,
            // dispatch_attempts.booking_id, and
            // provider_commission_receivables.booking_id are all
            // constrained()->cascadeOnDelete() onto bookings.id.
            //
            // Deliberately NOT included: `commissions` -- it is shared
            // across every module (parcel/taxi/marketplace/rental/hotel
            // orders all write to it too via booking_id-less rows), and
            // safely scoping a delete to "only the rows for bookings
            // being cleared" needs a WHERE clause this catalog's simple
            // whole-table model doesn't support yet. Left out rather than
            // rushed -- commissions rows for cleared bookings stay behind
            // as an accepted, documented gap in this first version.
            'tables' => [
                'chat_messages', 'reviews', 'booking_compensations',
                'dispatch_attempts', 'provider_commission_receivables', 'bookings',
            ],
        ],
        'wallet_transactions' => [
            'label' => 'Wallet Transaction History',
            'description' => 'The wallet_transactions ledger only. Does NOT touch wallets.balance (a separately-maintained stored total, not derived by summing this table) -- clearing this wipes transaction history, not anyone\'s current balance.',
            'tables' => ['wallet_transactions'],
        ],
        'notifications' => [
            'label' => 'Notification Campaigns & Logs',
            'description' => 'Sent notification records (notifications) and the campaigns that generated them (notification_campaigns). No FK between the two -- notifications.notifiable is a plain polymorphic reference, not a foreign key to notification_campaigns -- so no ordering dependency.',
            'tables' => ['notifications', 'notification_campaigns'],
        ],
    ];

    /**
     * Code-level safety floor -- NEVER offered as a selectable option by
     * the admin screen, and re-checked by DataClearService::run() at
     * execute time regardless of what MODULES claims, so a bug in the
     * catalog above can never defeat it on its own. Not configurable from
     * the UI by design; changing this list means changing this file, in a
     * reviewed pull request, not a settings toggle.
     */
    public const NEVER_CLEARABLE = [
        'users', 'role_assignments', 'roles', 'permissions', 'settings', 'activity_log',
    ];

    /** @return array<int, string> every real table name across the given module keys, unknown keys silently ignored. */
    public static function tablesFor(array $moduleKeys): array
    {
        $tables = [];

        foreach ($moduleKeys as $key) {
            $tables = array_merge($tables, self::MODULES[$key]['tables'] ?? []);
        }

        return array_values(array_unique($tables));
    }

    /** @return array<int, string> */
    public static function allModuleKeys(): array
    {
        return array_keys(self::MODULES);
    }

    public static function isKnownModule(string $key): bool
    {
        return array_key_exists($key, self::MODULES);
    }
}
