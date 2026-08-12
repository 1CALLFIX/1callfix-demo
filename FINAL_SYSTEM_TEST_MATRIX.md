# 1CallFix — Final System Test Matrix

Baseline: commit `939b270`. All rows below are real, executed tests — no "tested" entry exists without a corresponding test file/method. Executed against an isolated SQLite database, never production. Full suite: **101 passed, 0 failed, 256 assertions**, confirmed stable across 5 consecutive runs.

Legend: **METHOD** — `AT` automated test (PHPUnit/Livewire) · `RO` read-only production inspection · `CODE` static code inspection only (no automated test).

| Module | Feature | Role/Actor | Scope | Expected Result | Test Method | Status | Evidence |
|---|---|---|---|---|---|---|---|
| RBAC | Zones create | no permission | — | denied | AT | PASS | `ZonesAuthorizationTest::test_user_without_permission_cannot_create_zone` |
| RBAC | Zones create | zones.manage | own franchise | allowed | AT | PASS | `ZonesAuthorizationTest::test_user_with_matching_franchise_scope_can_create_zone` |
| RBAC | Zones create | zones.manage | different franchise | denied | AT | PASS | `ZonesAuthorizationTest::test_user_scoped_to_a_different_franchise_is_denied` |
| RBAC | Zones create | zones.manage | city, franchise within | allowed | AT | PASS | `ZonesAuthorizationTest::test_city_scoped_grant_covers_a_zone_in_a_franchise_within_that_city` |
| RBAC | Zones create | zones.manage | wrong city | denied | AT | PASS | `ZonesAuthorizationTest::test_city_scoped_grant_does_not_cover_a_different_city` |
| RBAC | Zones toggle/delete | no permission | — | denied | AT | PASS | `ZonesAuthorizationTest::test_toggle_active_is_denied_without_permission`, `test_delete_is_denied_without_permission` |
| RBAC | Zones create | super_admin | any | allowed | AT | PASS | `ZonesAuthorizationTest::test_super_admin_bypasses_scope_entirely` |
| RBAC | Categories CRUD | no permission / global grant / franchise grant (invalid) | global-only | denied/allowed/denied | AT | PASS | `CategoriesAuthorizationTest` (8 tests) |
| RBAC | Subcategories CRUD | categories.manage (shared, not new permission) | global-only | denied/allowed | AT | PASS | `CategoriesAuthorizationTest::test_subcategory_*` (3 tests) |
| RBAC | Services CRUD | no permission / global grant | global-only | denied/allowed | AT | PASS | `ServicesAuthorizationTest` (5 tests) |
| RBAC | Banners CRUD | franchise grant / global grant | franchise-targeted vs platform-wide | denied/allowed by target | AT | PASS | `BannersAuthorizationTest` (6 tests) |
| RBAC | CMS pages/FAQs | no permission / global grant / franchise grant (invalid) | global-only | denied/allowed/denied | AT | PASS | `CmsAuthorizationTest` (6 tests) |
| RBAC | Bookings createCustomer | no bookings.create anywhere / any-scope grant | scope-agnostic (canAnywhere) | denied/allowed | AT | PASS | `BookingCreationAuthorizationTest` (2 tests) |
| RBAC | Bookings addNewAddress/createBooking | zone-scoped grant / wrong zone | zone→franchise→city→country | allowed/denied | AT | PASS | `BookingCreationAuthorizationTest` (3 tests) |
| RBAC | Roles assign/revoke | no roles.manage | any | denied | AT | PASS | `RolesEscalationTest::test_actor_without_roles_manage_cannot_grant_super_admin` |
| RBAC | Roles assign | franchise-scoped roles.manage | own franchise / global / other franchise | allowed/denied/denied | AT | PASS | `RolesEscalationTest` (3 tests) |
| RBAC | Roles revoke | no permission | any | denied | AT | PASS | `RolesEscalationTest::test_actor_without_roles_manage_cannot_revoke_an_assignment` |
| RBAC | Roles assign | super_admin | global | allowed | AT | PASS | `RolesEscalationTest::test_super_admin_can_grant_super_admin_at_global_scope` |
| Booking FSM | Start (correct/wrong OTP) | provider | assigned booking | success/rejected | AT | PASS | `BookingFsmTest::test_correct_start_otp_*`, `test_wrong_start_otp_*` |
| Booking FSM | Start from invalid status | provider | searching_provider / already in_progress | rejected | AT | PASS | `BookingFsmTest::test_cannot_start_*` (2 tests) |
| Booking FSM | Complete (correct/wrong OTP) | provider | assigned booking | success/rejected | AT | PASS | `BookingFsmTest::test_correct_completion_otp_*`, `test_wrong_completion_otp_*` |
| Booking FSM | Complete by wrong provider | unrelated provider | — | rejected | AT | PASS | `BookingFsmTest::test_a_different_providers_booking_cannot_be_completed` |
| Booking FSM | Duplicate completion | provider | already completed | rejected, no 2nd commission | AT | PASS | `BookingFsmTest::test_duplicate_completion_is_rejected_and_does_not_double_apply_commission` |
| Booking FSM | Complete a cancelled booking | provider | cancelled | rejected | AT | PASS | `BookingFsmTest::test_a_cancelled_booking_cannot_be_completed` |
| Booking FSM | Approved extra-work items | provider | mixed approved/rejected | only approved added to price_final | AT | PASS | `BookingFsmTest::test_approved_extra_work_items_are_added_to_the_final_price` |
| Booking FSM | Admin cancel | admin | pre-service booking | success | AT | PASS | `BookingFsmTest::test_admin_can_cancel_a_pre_service_booking` |
| Booking FSM | Admin cancel completed/already-cancelled | admin | terminal states | rejected | AT | PASS | `BookingFsmTest::test_a_completed_booking_cannot_be_cancelled`, `test_an_already_cancelled_booking_cannot_be_cancelled_again` |
| Booking FSM | Admin reassign | admin | assigned booking, new provider | provider_id updated | AT | PASS | `BookingFsmTest::test_admin_reassign_moves_the_booking_to_a_new_provider` |
| Booking FSM | Admin reassign clears stale worker | admin | different new provider | assigned_worker_id nulled | AT | PASS | `BookingFsmTest::test_reassigning_to_a_different_provider_clears_a_stale_worker_assignment` |
| Booking FSM | Admin reassign a pending booking | admin | pending | OTPs generated, status→assigned | AT | PASS | `BookingFsmTest::test_reassigning_a_pending_booking_generates_otps_and_moves_to_assigned` |
| Dispatch | Late round vs. real acceptance | queued job | concurrent-equivalent | acceptance not clobbered | AT | PASS | `ServiceMatchingJobRaceTest::test_a_late_dispatch_round_does_not_clobber_a_real_concurrent_acceptance` |
| Dispatch | Late round vs. cancelled booking | queued job | — | booking not reopened | AT | PASS | `ServiceMatchingJobRaceTest::test_a_late_dispatch_round_does_not_reopen_a_cancelled_booking` |
| Dispatch | pending→searching_provider transition | queued job | round 1 | status + history written | AT | PASS | `ServiceMatchingJobRaceTest::test_first_round_transitions_pending_to_searching_and_records_history` |
| Dispatch | Job vs. deleted booking | queued job | — | clean no-op, no throw | AT | PASS | `ServiceMatchingJobRaceTest::test_job_no_ops_cleanly_when_booking_no_longer_exists` |
| Worker | Assign eligible active worker | partner | own team, matching capability | success | AT | PASS | `AssignBookingToWorkerActionTest::test_partner_can_assign_an_eligible_active_team_member` |
| Worker | Null-scoped capability matches any category | partner | — | success | AT | PASS | `test_capability_scoped_to_a_different_category_still_matches_via_null_scope` |
| Worker | Assign a booking not owned | partner | unrelated booking | denied | AT | PASS | `test_partner_cannot_assign_a_booking_they_do_not_own` |
| Worker | Assign after completion/cancellation | partner | terminal states | denied | AT | PASS | `test_completed_booking_cannot_be_assigned`, `test_cancelled_booking_cannot_be_assigned` |
| Worker | Assign inactive worker | partner | — | denied | AT | PASS | `test_inactive_worker_cannot_receive_assignment` |
| Worker | Assign worker not on team / on another partner's team | partner | — | denied | AT | PASS | `test_partner_cannot_assign_a_worker_not_on_their_team`, `test_partner_cannot_assign_an_unrelated_partners_worker` |
| Worker | Assign via suspended team link | partner | — | denied | AT | PASS | `test_suspended_team_link_cannot_receive_assignment` |
| Worker | Assign without/wrong capability | partner | — | denied | AT | PASS | `test_worker_without_matching_capability_is_rejected`, `test_worker_with_capability_scoped_to_a_different_category_is_rejected` |
| Worker | Commission ownership unaffected by delegation | partner | — | provider_id/status unchanged | AT | PASS | `test_assignment_does_not_change_provider_id_or_booking_status` |
| Financial | Commission idempotency on repeat call | system | — | same row, no double credit | AT | PASS | `CommissionIdempotencyTest::test_calling_apply_for_booking_twice_does_not_double_credit_the_wallet` |
| Financial | Commission split correctness | system | franchise-configured rates | matches expected split | AT | PASS | `CommissionIdempotencyTest::test_commission_split_matches_franchise_configured_rates` |
| Financial | DB-level uniqueness backstop | system | direct duplicate insert | rejected | AT | PASS | `CommissionIdempotencyTest::test_db_level_unique_constraint_rejects_a_direct_duplicate_insert` |
| Financial | Wallet ledger reconciliation | system | production wallets | opening+credits−debits=closing | RO | PASS | 0 mismatches (see `PRODUCTION_READINESS_AUDIT.md` §11) |
| Plan Engine | Free-plan subscribe | customer | — | active + entitlement balance granted | AT | PASS | `PlanEngineSmokeTest::test_subscribing_to_a_free_plan_activates_immediately_and_grants_entitlement_balance` |
| Plan Engine | Ineligible actor type / inactive plan | customer | — | rejected before any row mutated | AT | PASS | `test_ineligible_actor_type_cannot_purchase`, `test_inactive_plan_cannot_be_purchased` |
| Plan Engine | Cancel / pause / resume | customer | active subscription | correct status transitions | AT | PASS | `test_cancel_marks_auto_renew_false_*`, `test_pause_then_resume_round_trips_through_active` |
| Plan Engine | Renewal after scheduled upgrade | system (RenewalService) | — | new plan's entitlement granted, old closed not deleted | AT | PASS | `test_a_scheduled_upgrade_grants_the_new_plans_entitlements_at_renewal_not_the_old_ones` (regression for `4f92fdf`) |
| Loyalty | Earn/balance/idempotent duplicate | customer | — | correct balance, no double-earn | AT | PASS | `LoyaltyServiceTest` (3 tests) |
| Loyalty | Redeem to wallet | customer | valid/over-balance/below-minimum | success/rejected/rejected | AT | PASS | `LoyaltyServiceTest` (3 tests) |
| Loyalty | Expired points excluded | customer | — | balance excludes expired | AT | PASS | `test_expired_points_no_longer_count_toward_balance` |
| Referral | Signup create/no-referrer/self-referral/duplicate | customer | — | correct create/reject behavior | AT | PASS | `ReferralServiceTest` (4 tests) |
| Referral | Qualify on 1st/2nd completed booking | system | — | rewards once, not twice | AT | PASS | `ReferralServiceTest` (2 tests) |
| Referral | No pending referral | system | — | clean no-op | AT | PASS | `test_a_customer_with_no_referral_qualifying_is_a_no_op` |
| Database | Full migration chain from scratch | — | — | 122 migrations apply clean | AT (via test harness itself) | PASS | verified via `migrate`+`migrate:rollback`+re-`migrate` round-trip, this session |
| Database | Orphan/integrity sweep | — | production | 0 issues across 24 checks | RO | PASS (weak — near-zero data, see caveat) | `PRODUCTION_READINESS_AUDIT.md` §3 |

## Coverage Summary

**TOTAL TESTS:** 101
**TOTAL ASSERTIONS:** 256
**PASSED:** 101
**FAILED:** 0
**BLOCKED:** 0

**ADMIN SCREENS:**
TOTAL: 24 (Livewire module folders)
PASSED (functional QA per Phase 4 of the QA program — full 27-point-per-screen matrix): 0
FAILED: 0
NOT APPLICABLE / NOT ATTEMPTED: 24 — the exhaustive per-screen QA matrix (load/search/filter/sort/CRUD/confirmation/empty-state/loading-state/unauthorized-access/direct-HTTP-access per screen) was not run this session. RBAC enforcement on the mutation actions of 6 of these screens (Zones, Categories, Subcategories, Services, Banners, CMS) plus part of Bookings **is** covered by the RBAC test rows above — that is authorization coverage, not full functional QA.

**APIs:**
TOTAL: not inventoried this session (routes/api.php exists, not itemized)
TESTED: 0 dedicated API-endpoint tests (Worker delegation is tested at the Action layer, not through `WorkerJobController`/`PartnerWorkerController` HTTP endpoints directly)
FAILED: 0
NOT APPLICABLE: unknown until inventoried

**DATABASE TABLES:**
TOTAL: not exhaustively re-counted this session (119+ migrations, several add-column migrations rather than new tables)
COVERED (integrity-checked): 24 relationships across ~20 tables (see `PRODUCTION_READINESS_AUDIT.md` §3)
INTENTIONALLY EMPTY: `bookings`, `payments`, `commissions`, `wallets`, `subscriptions`, `plans`, `role_assignments` and others — production is pre-launch, confirmed via read-only count, not a defect
DORMANT/DEFERRED: `coupons`/`coupon_usages` (infrastructure retained, FK completed this session, no commercial feature built on top), `franchise_payout_ledger`, `franchise_applications`, `sos_alerts`, `activity_log` (all confirmed zero-consumer in prior audits, untouched this session)

**ROLES:**
TOTAL: 7 system roles (`super_admin`, `country_admin`, `city_admin`, `zone_admin`, `franchise_owner`, `operator`, `support`)
TESTED: scope mechanics tested via purpose-built single-permission test roles (not the 7 system roles directly, which bundle many permissions) — the underlying `AuthorizationService::can()`/`canAnywhere()` mechanics they all share are fully exercised

**SCOPES:**
TOTAL: 6 (`global`, `country`, `city`, `zone`, `module`, `franchise`)
TESTED: `global`, `franchise`, `city` directly exercised with both allow and cross-scope-deny cases; `country`/`zone`/`module` exercised only incidentally (scope array construction verified, not a dedicated positive-case test for each)

**PRINT TEMPLATES:**
TOTAL: 0 (system does not exist)
TESTED: 0

**E2E FLOWS:**
TOTAL: 0 browser-driven flows attempted
PASSED: 0
FAILED: 0
(Full multi-step business flows ARE covered end-to-end at the Action/Service layer — e.g. `BookingFsmTest` walks accept→start→complete→commission — but this is not the same as a QA-web-app-driven or browser-driven E2E test, which was not built.)

**SECURITY:**
CRITICAL: 0 found (bounded scope — see `PRODUCTION_READINESS_AUDIT.md` §4)
HIGH: 0 found (bounded scope)
MEDIUM: 0 found
LOW: 0 found
INFORMATIONAL: 1 — `SESSION_ENCRYPT=false` in `.env.example` (standard Laravel default, not unique to this app, noted for completeness)

**FINAL STATUS: NOT READY** — see `PRODUCTION_READINESS_AUDIT.md` §28 for the precise, itemized blockers and the qualified recommendation.
