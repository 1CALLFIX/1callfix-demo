<?php

namespace App\Contracts;

/**
 * Phase 22.2 (Order Engine Architecture Decision — see
 * PHASE_22_2_ORDER_ENGINE_ARCHITECTURE_DECISION.md for the full reasoning).
 * Deliberately minimal: the handful of facts every existing order-shaped
 * consumer (AuthorizationService::scopeQuery()'s franchise/zone columns,
 * TimezoneResolver's franchise lookup, every admin list screen's code/
 * status/price display) actually reads today, not a speculative full
 * feature surface guessed ahead of a second real implementer.
 *
 * `Booking` implements this today as a zero-risk, zero-behavior-change
 * addition (every method is a one-line delegation to an existing column).
 * No central engine (CommissionService/WalletService/DispatchService/
 * NotificationCenter) has been refactored to depend on this interface yet
 * — that refactor is deliberately deferred to Phase 22.4 (Parcel), the
 * first phase with a second real implementer to design it against. See
 * this contract's own decision document for why designing that refactor
 * now, with zero second implementer, was judged premature rather than
 * cautious.
 */
interface Orderable
{
    /** The vertical this order belongs to — one of App\Support\Modules::ALL's keys. */
    public function moduleCode(): string;

    /** Human/support-facing order code (e.g. bookings.code). */
    public function orderCode(): string;

    public function orderFranchiseId(): int;

    public function orderZoneId(): ?int;

    public function orderCustomerId(): int;

    /** Final price if settled, otherwise the quoted/estimated price. */
    public function orderTotalPrice(): float;

    /** The order's current lifecycle status, in whatever vocabulary that vertical's own FSM uses. */
    public function orderStatus(): string;
}
