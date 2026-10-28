<?php

namespace App\Support;

/**
 * The queue that job offers run on: every dispatch round (services, bundles, parcel, taxi, marketplace) and the
 * offer push to providers and riders. Kept apart from `default` so a backup, an email batch or a slow SMTP send
 * never makes an offer wait.
 *
 * Unset (DISPATCH_QUEUE) = null: nothing is set on the job, so it goes to the connection's own queue exactly as
 * before, and deploying the code changes nothing until a worker listens on the dispatch queue. Switch order on a server: workers listen on `dispatch` first
 * (deploy/supervisor/), THEN set DISPATCH_QUEUE=dispatch and re-cache config — never the other way round, or
 * offers sit in a queue nobody reads.
 */
final class DispatchQueue
{
    /** null = not configured: leave the job on the connection's default queue. */
    public static function name(): ?string
    {
        $name = trim((string) config('queue.dispatch_queue'));

        return $name !== '' ? $name : null;
    }
}
