<?php

namespace App\Services;

use App\Models\Franchise;
use Illuminate\Support\Carbon;

/**
 * Phase 21 item TECH-3. ONE reusable display-layer timezone-conversion
 * mechanism, generalizing DocumentService::forPayment()'s own
 * `$country?->default_timezone ?: config('app.timezone')` pattern -- the
 * single place in this codebase that already did this correctly, extended
 * rather than duplicated. See KNOWN_RISKS_AND_DECISIONS.md item 27 and
 * PHASE_21_RELEASE_CANDIDATE_BACKLOG.md item TECH-3 for the full audit
 * this was built against.
 *
 * Display-only, by construction: `format()` clones the given Carbon
 * instance before calling `setTimezone()` -- the caller's own object (and
 * therefore the underlying stored/compared UTC instant) is never mutated.
 * Nothing here touches a query, a `where()` comparison, dispatch/OTP
 * timing, or API/mobile JSON serialization (Blade-layer callers only).
 *
 * Each caller supplies its OWN already-resolved (and, where relevant,
 * eager-loaded) Franchise -- this class does not attempt to auto-discover
 * "the" franchise for an arbitrary model, matching the same "each screen
 * supplies its own scope/columns" convention AuthorizationService::
 * scopeQuery() already established. This also keeps the class itself
 * N+1-safe: it never issues a query of its own -- the eager-loading
 * responsibility (e.g. `->with('franchise.country')`) stays with the
 * caller's own existing query, exactly like every other scoped screen in
 * this codebase already does for its own `$columns` relation paths.
 *
 * Scope note: only models reachable via a direct or single-hop Franchise
 * relation are converted by this mechanism. A handful of screens display
 * timestamps on scope_type/scope_id-shaped rows (PerformanceCampaign,
 * FlashSale's own starts_at/ends_at, BadgeAssignment, NotificationCampaign/
 * NotificationMeeting, Plan) -- a genuinely different resolution shape
 * (global scope has no single franchise to convert to at all; city scope
 * needs an extra City->Country hop) that was deliberately left
 * unconverted this pass rather than folded into this same mechanism
 * without its own dedicated design pass -- logged as a new finding, not
 * silently resolved or ignored.
 */
class TimezoneResolver
{
    public function timezoneFor(?Franchise $franchise): string
    {
        return $franchise?->country?->default_timezone ?: config('app.timezone');
    }

    /**
     * @param  Carbon|\DateTimeInterface|null  $moment
     */
    public function format($moment, ?Franchise $franchise, string $format = 'd M Y, h:i A'): ?string
    {
        if (! $moment) {
            return null;
        }

        $carbon = $moment instanceof Carbon ? $moment : Carbon::instance($moment);

        return $carbon->clone()->setTimezone($this->timezoneFor($franchise))->format($format);
    }
}
