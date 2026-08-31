<?php

namespace App\Services;

use App\Models\Country;
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
    private ?string $platformTimezoneMemo = null;

    public function timezoneFor(?Franchise $franchise): string
    {
        return $franchise?->country?->default_timezone ?: $this->platformTimezone();
    }

    /**
     * The timezone to use when there is no franchise to narrow it -- a
     * platform-wide banner (`franchise_id` is null) or a customer-web
     * screen that has no franchise resolved yet. Where the platform serves
     * exactly one country (the current state: India / `Asia/Kolkata`) that
     * country's own `default_timezone` IS the only correct answer -- it is
     * the same franchise -> country -> default_timezone chain `timezoneFor()`
     * already trusts, just started from Country directly. A bare
     * `config('app.timezone')` (UTC) fallback here is exactly the display
     * bug this pass removes.
     *
     * If the platform ever becomes genuinely multi-country, "the" platform
     * wall clock is no longer well defined (a global banner has no single
     * country whose midnight it starts at) and this returns
     * `config('app.timezone')` -- the same deliberate scope boundary this
     * class's header already draws for global/city-scoped rows, logged as
     * a finding to design rather than guessed at here.
     *
     * Memoised per instance: bound as a singleton (AppServiceProvider), so
     * a paginated list resolving this per row issues one query, not N.
     */
    public function platformTimezone(): string
    {
        return $this->platformTimezoneMemo ??= (function (): string {
            $zones = Country::query()
                ->whereNotNull('default_timezone')
                ->where('default_timezone', '!=', '')
                ->distinct()
                ->pluck('default_timezone');

            return $zones->count() === 1 ? (string) $zones->first() : config('app.timezone');
        })();
    }

    /**
     * INPUT boundary -- the inverse of format(). A naive wall-clock string
     * the user typed (an HTML `datetime-local` value carries no offset) is
     * interpreted as being in their resolved timezone and returned as the
     * UTC Carbon to store.
     *
     * The `->utc()` is load-bearing: assigning a non-UTC Carbon to an
     * Eloquent `datetime`-cast attribute stores its LITERAL wall clock, not
     * the converted instant (verified against Laravel 13) -- i.e. dropping
     * the `->utc()` reintroduces the very bug this fixes.
     */
    public function toUtc(?string $localWallClock, ?Franchise $franchise = null): ?Carbon
    {
        if ($localWallClock === null || trim($localWallClock) === '') {
            return null;
        }

        return Carbon::parse(trim($localWallClock), $this->timezoneFor($franchise))->utc();
    }

    /**
     * A stored UTC moment rendered back as a naive `datetime-local` input
     * value (`Y-m-d\TH:i`) in the resolved timezone, so an edit form shows
     * the operator the same wall clock the live surface will show.
     */
    public function toLocalInput($moment, ?Franchise $franchise = null): ?string
    {
        return $this->format($moment, $franchise, 'Y-m-d\TH:i');
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
