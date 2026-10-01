# Job Journey ("job span") design — REF 1CF-JOURNEY-001

One journey model for every module. It describes how the statuses the platform already uses read as a story, who
moves each step, and what the customer, provider and operator see. **It does not change any state machine or any
financial trigger** — it is a presentation layer over the existing statuses and history, plus the one new step
("spares available") recorded in the existing status history.

## 1. The shape of a journey

```
 main lane        step 1 ─► step 2 ─► step 3 ─► … ─► final step        (each step = a status the module already uses)
                                   │
 hold lane (service jobs only)     └─► On hold (reason) ─► Spares available* ─► Work resumed ─► (back on the main lane)
                                   * only when the reason is "waiting for spare parts"

 early exits      Cancelled  (who / why / fee / where the refund went)        Under review (dispute)
```

The timeline always shows: a headline for "where are we now", a progress bar, chips (Scheduled for …, Paid / Payment
pending / Pay in cash after the job), the steps with times, hold episodes, and a terminal outcome when there is one.

## 2. Service job (home services) — how every span works

| Span (status) | Meaning | Moved by | Customer is told | Provider sees | Operator can |
|---|---|---|---|---|---|
| **Booked** (`pending`) | Immediate booking: a moment in the queue. **Scheduled booking: waiting for payment** to be confirmed before we start looking | system | "Booking confirmed" (existing); timeline says *Scheduled for … We start looking as soon as your payment is confirmed* | — | cancel |
| **Finding a professional** (`searching_provider`) | open offers out | system (matching / scheduled release) | timeline | offers | reassign, cancel |
| **Professional assigned** (`assigned`) | accepted | provider (accept) / operator | "Provider assigned" (existing) | start OTP step | reassign, cancel |
| **On the way** (`provider_en_route`) | optional step | provider ("I'm on my way") | **new** push + in-app: *"{name} is heading to you"* | Start | cancel |
| **Work in progress** (`in_progress`) | started with the start OTP | provider | **new** push + in-app: *"Work has started"* | Complete / Waiting for spares | hold, cancel |
| **On hold** (`on_hold`) | paused; reason shown | provider (spares only) / operator | **new**, every channel: *"Your job is on hold (Waiting for spare parts)"* | Spares available / Resume | spares available, resume, cancel |
| **Spares available** | parts arrived; job still on hold | provider / operator | **new** push + in-app | Resume work | resume, cancel |
| **Work resumed** | back to in progress | provider / operator | **new** push + in-app | Complete | — |
| **Completed** (`completed`) | **authoritative financial settlement trigger** (final price, commission, provider wallet, loyalty, receipt) | provider (completion OTP) | "Booking completed" (existing) | earnings line | — |
| **Cancelled** (`cancelled`) | from any non-terminal status | customer / operator / platform (no provider found) | "Booking cancelled" / "could not be matched" (existing) | cancelled | — |
| **Under review** (`disputed`) | flagged | operator | — | — | — |

Rules that keep it safe:
- A hold can only be placed once a job is **in progress** (resume always returns to in progress, so holding earlier
  would skip the start OTP).
- A provider can only hold for **spare parts**, and only resume a spares hold; every other hold reason stays a
  dispatcher decision.
- A hold never touches payment, commission, wallet or fees; money moves only on capture, completion and cancellation.
- Minor stage notifications use push and in-app only (never email/SMS), so one job does not produce five messages.

### Pending, in detail
`pending` has two real meanings (see `CreateBookingAction`): for an **immediate** booking it is the instant before
matching starts; for a **scheduled** booking it is the state where the booking waits **unpaid** until payment confirms,
then dispatch is released. The timeline says which one applies and shows a *Payment pending* chip.

### Cancel, in detail
The cancelled outcome states, in plain words: why (customer cancelled / no professional found in time / the operator's
reason), the **cancellation fee** (or "No cancellation fee."), and **where the refund went** (your 1CallFix wallet vs
the original payment method, 3–5 working days). Steps that were never reached are dropped, so the line stops where the
job stopped.

## 3. Other modules (definitions in `JourneyCatalog`, shown on each module's admin detail)

| Module | Main lane (status → label) | Early exits |
|---|---|---|
| **Parcel delivery** | `pending` Order placed → `searching_worker` Finding a rider → `assigned` Rider assigned → `worker_en_route_pickup` Heading to pickup → `picked_up` Parcel picked up → `en_route_dropoff` Out for delivery → `delivered` Delivered | cancelled, disputed |
| **Taxi** | `requested` → `searching_driver` → `assigned` Driver assigned → `driver_en_route` Driver arriving → `trip_started` → `trip_completed` | cancelled, disputed |
| **Food delivery** | `pending` Order placed → `accepted` → `preparing` → `ready` → *(`out_for_delivery`, appears once delivery tracking statuses exist)* → `completed` Delivered | cancelled |
| **Grocery / pharmacy / e-commerce** | same lane, wording "Packing your items" | cancelled |
| **Hotel** | `pending` Booking requested → `confirmed` → `checked_in` → `checked_out` → `completed` | cancelled |
| **Vehicle / equipment rental** | `pending` Reserved → `confirmed` → `picked_up` → `active` In use → `returned` → `completed` | cancelled |
| **Property stay** | `pending` Requested → `confirmed` → `checked_in` → `completed` | cancelled |

A test asserts that **every real status of every module has a place in its journey**, so a new status added to a state
machine without a journey entry fails the suite.

## 4. Where it lives

| Piece | File |
|---|---|
| Definitions (steps, hints, terminals, hold reasons) | `app/Support/Journey/JourneyCatalog.php` |
| Builder (statuses + history + context → view model) | `app/Support/Journey/JourneyBuilder.php` |
| Context (schedule, payment, cancellation reason / fee / refund) | `app/Support/Journey/JourneyContext.php` |
| Customer stage notifications | `app/Support/Journey/StageNotifier.php`, `BookingStatusNotification` |
| Timeline component | `resources/views/components/journey/timeline.blade.php` |
| Spares step | `app/Actions/MarkSparesAvailableAction.php` |
| Provider web | `Livewire/Provider/Jobs/Show` (Waiting for spares / Spares available / Resume work) |
| Admin | `Livewire/Bookings/Show` (Job controls; permission `bookings.reassign`) |
| API | `app/Http/Controllers/API/JobJourneyController.php` |

API (all `auth:sanctum`; transitions need the booking's provider or its assigned field worker):
`GET /api/bookings/{id}/journey` (customer, provider or assigned worker),
`POST /api/bookings/{id}/en-route`, `/start` (`otp`), `/hold-for-spares` (`note?`), `/spares-available`, `/resume`.
Responses carry `booking` and `journey` (timestamps as ISO 8601) so the native app can draw the same timeline.

## 5. Adding a module or a step
Add one entry (or one row) to `JourneyCatalog::all()`; add the module's real statuses to the coverage test; render
`<x-journey.timeline :journey="…" />` with `JourneyBuilder::build(<key>, $status, $history[, $context])`.
