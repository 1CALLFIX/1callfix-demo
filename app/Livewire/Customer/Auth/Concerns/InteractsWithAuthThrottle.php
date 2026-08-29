<?php

namespace App\Livewire\Customer\Auth\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Shared rate-limiting for the customer web auth screens.
 *
 * Livewire component actions all share the one /livewire/update endpoint,
 * so route-level `throttle:` middleware never sees them — every auth
 * component must throttle its own sensitive actions or it is an
 * un-throttled brute-force / SMS-cost surface. Same RateLimiter-facade
 * pattern App\Livewire\Auth\Login (admin) and the pre-rebuild customer
 * Login already used; numbers match routes/api.php (`throttle:5,1` /
 * `throttle:10,1`) rather than invented ones.
 *
 * Two keys per action: one per (identifier, IP) so a single abuser cannot
 * lock out a real customer behind the same NAT/carrier IP, and one per IP
 * alone so a spray across many identifiers from one host is still slowed.
 */
trait InteractsWithAuthThrottle
{
    protected function throttleKeys(string $action, string $identifier): array
    {
        $ip = request()->ip();

        return [
            'cust-auth:'.$action.'|'.Str::lower($identifier).'|'.$ip,
            'cust-auth:'.$action.'|ip|'.$ip,
        ];
    }

    /**
     * Returns true (and sets $this->error) when either key is over the
     * limit. Call before doing the work.
     */
    protected function isThrottled(string $action, string $identifier, int $maxPerIdentifier = 5, int $maxPerIp = 20): bool
    {
        [$idKey, $ipKey] = $this->throttleKeys($action, $identifier);

        foreach ([[$idKey, $maxPerIdentifier], [$ipKey, $maxPerIp]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->error = 'Too many attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.';

                return true;
            }
        }

        return false;
    }

    protected function hitThrottle(string $action, string $identifier, int $decaySeconds = 60): void
    {
        foreach ($this->throttleKeys($action, $identifier) as $key) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }

    protected function clearThrottle(string $action, string $identifier): void
    {
        foreach ($this->throttleKeys($action, $identifier) as $key) {
            RateLimiter::clear($key);
        }
    }
}
