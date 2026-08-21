# Migration Risk Classification — companion to `DATABASE_AUDIT_2026-08-21.md`

Full detail behind that document's §3. All 107 pending migrations were opened and their actual `up()`/`down()` bodies read directly — nothing here was inferred from a filename. See the main audit document for the plain-language summary, table inventory, data reality assessment, and the §3c live-code-dependency finding.

**Important caveat on the "can literally error at migrate-time" shortlist below:** given the real row counts confirmed in the main audit's §1 (`commissions`=0, `bookings`=0, `reviews`=0), none of the three listed FK/unique-constraint migrations would actually fail if run against *this* database today — there are no existing rows to violate the new constraint. They are flagged as RISKY-STRUCTURAL because the *mechanism itself* is the kind that fails against a populated table, not because failure is expected here specifically. That distinction matters for reading this table correctly: it classifies migrations by what they structurally do, not by whether today's near-empty database happens to be safe against them.

| Migration | Classification | Explanation |
|---|---|---|
| 2026_08_12_001000_add_unique_constraint_to_commissions_booking_id | RISKY-STRUCTURAL | Adds a UNIQUE constraint on the existing, already-populated `commissions.booking_id` column — could fail at migrate-time if any duplicate booking_id rows exist. |
| 2026_08_12_002000_add_foreign_key_to_bookings_coupon_id | RISKY-STRUCTURAL | Adds a real FK constraint on the existing `bookings.coupon_id` column referencing `coupons.id` — could fail if any existing row holds a coupon_id with no matching coupon. |
| 2026_08_12_003000_add_indexes_to_bookings_table | SAFE-ADDITIVE | Adds two plain (non-unique) indexes on `bookings.status` / `bookings.completed_at`. |
| 2026_08_13_001000_harden_otps_table_for_shared_engine | RISKY-STRUCTURAL | Renames the existing `otps.code` column to `otps.code_hash` (a real column rename), plus adds several nullable/defaulted columns and an index. |
| 2026_08_13_002000_create_qr_challenges_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_001000_seed_operations_permissions | SAFE-ADDITIVE | Seeds 2 new permission rows + role_permission links. |
| 2026_08_14_002000_seed_payments_permission | SAFE-ADDITIVE | Seeds 1 new permission row. |
| 2026_08_14_003000_create_badges_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_004000_create_badge_assignments_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_005000_seed_badge_definitions | SAFE-ADDITIVE | Seeds 7 new rows into the just-created `badges` table. |
| 2026_08_14_006000_seed_badges_permissions | SAFE-ADDITIVE | Seeds 2 new permission rows. |
| 2026_08_14_007000_create_flash_sales_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_008000_create_flash_sale_targets_table | SAFE-ADDITIVE | New table (pivot). |
| 2026_08_14_009000_create_flash_sale_redemptions_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_010000_seed_flash_sales_permissions | SAFE-ADDITIVE | Seeds 2 new permission rows. |
| 2026_08_14_011000_add_fraud_and_expiry_to_referrals_table | RISKY-STRUCTURAL | Widens `referrals.status` enum via `->change()` (pending/rewarded → +expired/fraud_flagged) and adds a new nullable FK `fraud_flagged_by`; enum redefinition is a structural column change even though it only adds values. |
| 2026_08_14_012000_seed_loyalty_manage_permission | SAFE-ADDITIVE | Seeds 1 new permission row. |
| 2026_08_14_013000_create_performance_campaigns_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_014000_create_performance_campaign_participants_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_015000_seed_performance_campaigns_permissions | SAFE-ADDITIVE | Seeds 3 new permission rows. |
| 2026_08_14_016000_add_kyc_lifecycle_columns_to_providers_table | RISKY-STRUCTURAL | Widens `providers.kyc_status` enum via `->change()`, adds new nullable columns, AND runs a real UPDATE against every existing non-approved provider row to backfill `kyc_deadline_at` from `created_at + 30 days` — a genuine data mutation of existing rows, not a fresh insert. |
| 2026_08_14_017000_widen_kyc_status_on_field_workers_table | RISKY-STRUCTURAL | Widens `field_workers.kyc_status` enum via `->change()` (pending/approved/rejected → +draft/submitted/under_review/resubmission_required/expired). |
| 2026_08_14_018000_add_security_columns_to_provider_documents_table | SAFE-ADDITIVE | Loosens `file_url` from NOT NULL to nullable (safe direction — no existing value violates this) and adds new nullable columns/FKs + an index. |
| 2026_08_14_019000_add_security_columns_to_field_worker_documents_table | SAFE-ADDITIVE | Mirrors 018000 exactly on `field_worker_documents`. |
| 2026_08_14_020000_create_kyc_document_requirements_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_021000_seed_kyc_document_requirements | SAFE-ADDITIVE | Seeds 12 new rows into the just-created table. |
| 2026_08_14_022000_create_kyc_verification_videos_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_023000_create_kyc_withdrawal_exceptions_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_024000_create_kyc_support_requests_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_025000_seed_kyc_permissions | SAFE-ADDITIVE | Seeds 4 new permission rows. |
| 2026_08_14_026000_create_booking_compensations_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_027000_seed_bookings_compensate_permission | SAFE-ADDITIVE | Seeds 1 new permission row. |
| 2026_08_14_028000_create_document_number_sequences_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_029000_create_generated_documents_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_030000_create_payment_webhook_logs_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_031000_create_scheduled_task_runs_table | SAFE-ADDITIVE | New table. |
| 2026_08_14_032000_add_is_active_to_content_pages_table | SAFE-ADDITIVE | New `is_active` boolean column on `content_pages`, default true. |
| 2026_08_15_001000_add_unique_booking_id_to_reviews_table | RISKY-STRUCTURAL | Adds a UNIQUE constraint on the existing, already-populated `reviews.booking_id` column — could fail if any duplicate booking_id rows already exist. |
| 2026_08_15_002000_add_external_id_to_service_categories_table | SAFE-ADDITIVE | New nullable+unique column `external_id`; since the column is brand new, every existing row gets NULL, and NULLs are excluded from uniqueness checks on every DB engine this app runs on — cannot fail against existing data. |
| 2026_08_15_003000_add_external_id_to_service_subcategories_table | SAFE-ADDITIVE | Same as 002000, on `service_subcategories`. |
| 2026_08_15_004000_add_external_id_to_services_table | SAFE-ADDITIVE | Same as 002000, on `services`. |
| 2026_08_15_005000_create_catalog_import_runs_table | SAFE-ADDITIVE | New table. |
| 2026_08_15_010000_add_scale_indexes_to_hot_status_columns | SAFE-ADDITIVE | Adds 9 plain (non-unique) indexes across `payments`, `bookings`, `wallet_transactions`, `dispatch_attempts`, `payouts`, `referrals`, `notification_logs`, `franchises`, `users`. |
| 2026_08_16_001000_seed_chat_view_permission | SAFE-ADDITIVE | Seeds 1 new permission row. |
| 2026_08_16_900000_add_is_implemented_to_modules_table | RISKY-STRUCTURAL | Adds new `is_implemented` boolean (default false, safe) but then executes a real UPDATE against the existing `modules` row where `code='service'`, setting `is_implemented=true` — a data mutation of an existing row's meaning, not a fresh insert. |
| 2026_08_16_901000_create_module_activations_table | SAFE-ADDITIVE | New table. |
| 2026_08_16_902000_backfill_service_module_activations | SAFE-ADDITIVE | Inserts new rows (via `insertOrIgnore`) into the brand-new `module_activations` table, one per existing franchise id — reads `franchises` but never writes to it; functionally reproduces the pre-existing implicit fallback behavior, not a mutation of any existing row's data. |
| 2026_08_16_903000_seed_modules_manage_permission | SAFE-ADDITIVE | Seeds 1 new permission row. |
| 2026_08_17_001000_create_parcel_orders_table | SAFE-ADDITIVE | New table. |
| 2026_08_17_002000_create_parcel_order_status_history_table | SAFE-ADDITIVE | New table. |
| 2026_08_17_003000_create_parcel_order_sequences_table | SAFE-ADDITIVE | New table. |
| 2026_08_17_004000_add_parcel_order_id_to_commissions_table | SAFE-ADDITIVE | Loosens `commissions.booking_id` to nullable (safe direction — every existing row already non-null) and adds new nullable FK `parcel_order_id` + a unique constraint on that brand-new, all-null column (cannot fail; `parcel_orders` is a new empty table). |
| 2026_08_17_005000_add_parcel_order_id_to_payments_table | RISKY-STRUCTURAL | Widens `payments.purpose` enum via `->change()` to add `'parcel_order'` — a real enum redefinition on an existing, populated column. |
| 2026_08_17_006000_add_dispatchable_to_dispatch_attempts_table | SAFE-ADDITIVE | Loosens `booking_id`/`provider_id` to nullable (safe direction) and adds new nullable polymorphic columns + index. |
| 2026_08_17_007000_seed_parcel_orders_permissions | SAFE-ADDITIVE | Seeds 3 new permission rows. |
| 2026_08_17_100000_create_taxi_rides_table | SAFE-ADDITIVE | New table. |
| 2026_08_17_101000_create_taxi_ride_status_history_table | SAFE-ADDITIVE | New table. |
| 2026_08_17_102000_create_taxi_ride_sequences_table | SAFE-ADDITIVE | New table. |
| 2026_08_17_103000_add_taxi_ride_id_to_commissions_and_payments_tables | RISKY-STRUCTURAL | Widens `payments.purpose` enum via `->change()` to add `'taxi_ride'` (the new `taxi_ride_id` FK/unique additions themselves are on brand-new, all-null columns and are safe). |
| 2026_08_17_104000_seed_taxi_rides_permissions | SAFE-ADDITIVE | Seeds 3 new permission rows. |
| 2026_08_18_001000_create_property_types_table | SAFE-ADDITIVE | New table. |
| 2026_08_18_002000_create_properties_table | SAFE-ADDITIVE | New table. |
| 2026_08_18_003000_create_property_amenities_tables | SAFE-ADDITIVE | Two new tables (lookup + pivot). |
| 2026_08_18_004000_create_property_reservations_table | SAFE-ADDITIVE | New table. |
| 2026_08_18_005000_create_property_availabilities_table | SAFE-ADDITIVE | New table. |
| 2026_08_18_006000_create_property_reservation_status_history_table | SAFE-ADDITIVE | New table. |
| 2026_08_18_007000_create_property_reservation_sequences_table | SAFE-ADDITIVE | New table. |
| 2026_08_18_008000_add_property_reservation_id_to_commissions_payments_reviews | RISKY-STRUCTURAL | Widens `payments.purpose` enum via `->change()` to add `'property_reservation'`; also loosens `reviews.booking_id` to nullable (safe) and adds new nullable FK columns (safe) on commissions/reviews. |
| 2026_08_18_009000_seed_property_rental_permissions | SAFE-ADDITIVE | Seeds 3 new permission rows. |
| 2026_08_18_025638_add_field_worker_to_payouts_payee_type_enum | RISKY-STRUCTURAL | Widens `payouts.payee_type` enum via `->change()` from `[provider, franchise_owner]` to `[provider, field_worker, franchise_owner]` — a real enum redefinition on an existing, populated column. |
| 2026_08_19_001000_create_stores_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_002000_create_marketplace_categories_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_003000_create_products_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_004000_create_product_variants_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_005000_create_add_ons_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_006000_create_cart_items_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_007000_create_marketplace_orders_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_008000_create_marketplace_order_items_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_009000_create_marketplace_order_status_history_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_010000_create_marketplace_order_sequences_table | SAFE-ADDITIVE | New table. |
| 2026_08_19_011000_add_marketplace_order_id_to_commissions_payments_reviews | RISKY-STRUCTURAL | Widens `payments.purpose` enum via `->change()` to add `'marketplace_order'` (the new `marketplace_order_id` FK/unique additions on commissions/reviews are on brand-new, all-null columns and are safe). |
| 2026_08_19_012000_seed_marketplace_permissions | SAFE-ADDITIVE | Seeds 5 new permission rows. |
| 2026_08_20_001000_rename_car_rental_module_to_property_rental | RISKY-STRUCTURAL | `UPDATE modules SET code='property_rental', name='Property Rental' WHERE code='car_rental'` — targets the `modules` registry table only (currently 9 rows), does NOT touch the `bookings` table, and per the migration's own docblock `module_activations.module_id` is an integer FK (not the code string) so no orphaning occurs. |
| 2026_08_21_001000_rename_property_rental_module_to_rental | RISKY-STRUCTURAL | `UPDATE modules SET code='rental', name='Rental' WHERE code='property_rental'` — targets the `modules` registry table only, does NOT touch the `bookings` table, and per the migration's own docblock `module_activations.module_id` is an integer FK (not the code string) so no orphaning occurs. |
| 2026_08_21_002000_create_vehicle_categories_table | SAFE-ADDITIVE | New table. |
| 2026_08_21_003000_create_vehicles_table | SAFE-ADDITIVE | New table. |
| 2026_08_21_004000_create_equipment_categories_table | SAFE-ADDITIVE | New table. |
| 2026_08_21_005000_create_equipment_items_table | SAFE-ADDITIVE | New table. |
| 2026_08_21_006000_create_rental_reservations_table | SAFE-ADDITIVE | New table. |
| 2026_08_21_007000_create_rental_reservation_status_history_table | SAFE-ADDITIVE | New table. |
| 2026_08_21_008000_create_rental_reservation_sequences_table | SAFE-ADDITIVE | New table. |
| 2026_08_21_009000_add_rental_reservation_id_to_commissions_payments_reviews | RISKY-STRUCTURAL | Widens `payments.purpose` enum via `->change()` to add `'rental_reservation'` (the new `rental_reservation_id` FK/unique additions on commissions/reviews are on brand-new, all-null columns and are safe). |
| 2026_08_21_010000_seed_rental_permissions | SAFE-ADDITIVE | Seeds 4 new permission rows. |
| 2026_08_22_001000_rename_bookings_module_to_hotel | RISKY-STRUCTURAL | `UPDATE modules SET code='hotel', name='Hotel / Stay' WHERE code='bookings'` — targets the `modules` registry table only, does NOT touch the `bookings` table (the migration's own docblock confirms the `'bookings'` module code has zero consumers anywhere else in the codebase), and `module_activations.module_id` is an integer FK (not the code string) so no orphaning occurs. |
| 2026_08_22_002000_create_accommodation_types_table | SAFE-ADDITIVE | New table + seeds 6 fresh rows into it. |
| 2026_08_22_003000_create_accommodations_table | SAFE-ADDITIVE | New table. |
| 2026_08_22_004000_create_accommodation_amenities_tables | SAFE-ADDITIVE | Two new tables (lookup + pivot). |
| 2026_08_22_005000_create_hotel_room_types_table | SAFE-ADDITIVE | New table. |
| 2026_08_22_006000_create_hotel_rate_plans_table | SAFE-ADDITIVE | New table. |
| 2026_08_22_007000_create_hotel_room_availabilities_table | SAFE-ADDITIVE | New table. |
| 2026_08_22_008000_create_hotel_reservations_table | SAFE-ADDITIVE | New table. |
| 2026_08_22_009000_create_hotel_reservation_rooms_table | SAFE-ADDITIVE | New table. |
| 2026_08_22_010000_create_hotel_guests_table | SAFE-ADDITIVE | New table. |
| 2026_08_22_011000_create_hotel_reservation_status_history_table | SAFE-ADDITIVE | New table. |
| 2026_08_22_012000_create_hotel_reservation_sequences_table | SAFE-ADDITIVE | New table. |
| 2026_08_22_013000_add_hotel_reservation_id_to_commissions_payments_reviews | RISKY-STRUCTURAL | Widens `payments.purpose` enum via `->change()` to add `'hotel_reservation'` (the new `hotel_reservation_id` FK/unique additions on commissions/reviews are on brand-new, all-null columns and are safe). |
| 2026_08_22_014000_seed_hotel_permissions | SAFE-ADDITIVE | Seeds 3 new permission rows. |

## Summary

- **SAFE-ADDITIVE: 89**
- **RISKY-STRUCTURAL: 18**
- **UNCLEAR: 0**
- **Total: 107**

All 107 pending migration files were read and every `up()`/`down()` body inspected directly (no filename-only guessing). Zero were classified UNCLEAR — every mechanism was confidently determinable from the source.

### RISKY-STRUCTURAL migrations that add a foreign key or unique constraint on an *existing, already-populated* column (the ones that can literally error out at migrate-time if a violating row exists today, as opposed to enum widenings or new-nullable-column constraints that cannot fail against existing data):

1. `2026_08_12_001000_add_unique_constraint_to_commissions_booking_id` — UNIQUE on existing `commissions.booking_id`.
2. `2026_08_12_002000_add_foreign_key_to_bookings_coupon_id` — FK on existing `bookings.coupon_id` → `coupons.id`.
3. `2026_08_15_001000_add_unique_booking_id_to_reviews_table` — UNIQUE on existing `reviews.booking_id`.

(The remaining 15 RISKY-STRUCTURAL migrations are enum widenings via `->change()`, a column rename, or UPDATE-based data backfills/row renames — structurally risky in the abstract but not new-constraint-against-existing-data failures. Several of them, e.g. the `payments.purpose` enum-widen migrations, do also add new FK/unique constraints, but always on brand-new, all-null columns referencing brand-new empty tables — these cannot fail against existing data and are therefore not included in the list above.)
