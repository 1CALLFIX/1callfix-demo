<?php

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export Everywhere session, Part 1 — the one reusable export mechanism
 * every screen's own `exportXxxCsv()` action method calls, instead of each
 * screen hand-rolling its own CSV-writing loop. This trait owns HOW a CSV
 * gets built and streamed; it deliberately knows NOTHING about WHAT query
 * or scope any given screen needs — that stays entirely with the caller,
 * because:
 *
 *  - Filter-state correctness: the caller passes ITS OWN already-filtered
 *    query builder (the exact same one its render() method paginates —
 *    typically a shared private method both call, so the two can never
 *    drift apart). This trait never re-derives filters.
 *  - Scope correctness (the most important requirement in this whole
 *    session): the caller's query must ALREADY be the output of
 *    AuthorizationService::scopeQuery() — same as every other screen in
 *    this codebase. This trait has no opinion on permissions and adds no
 *    WHERE clause of its own; if the caller passes an unscoped query, the
 *    export is unscoped. Every real call site in this codebase passes a
 *    scoped query (see each screen's own exportXxxCsv() method) and this
 *    is covered by a dedicated regression suite (tests/Feature/Export).
 *
 * Streams via Laravel's own streamDownload() (chunked writes straight to
 * the response, never materializes the whole file/collection in memory)
 * -- chunk()'d reads off the query builder so a genuinely large table
 * (Bookings, Payments) doesn't load every row into memory just to write
 * it back out one line at a time either.
 */
trait HasCsvExport
{
    /**
     * @param  Builder  $query  Already filtered AND scope-checked — see class docblock.
     * @param  array<int, string>  $headings
     * @param  callable(object): array<int, mixed>  $rowMapper  One model -> one CSV row's cell values, in the SAME order as $headings.
     */
    protected function streamCsvExport(string $filename, Builder $query, array $headings, callable $rowMapper, int $chunkSize = 200): StreamedResponse
    {
        $keyName = $query->getModel()->getQualifiedKeyName();

        return response()->streamDownload(function () use ($query, $headings, $rowMapper, $chunkSize, $keyName) {
            $out = fopen('php://output', 'w');

            // A UTF-8 BOM so Excel (still the most common opener for an
            // admin-downloaded CSV) doesn't mangle non-ASCII characters —
            // this codebase's own currency symbol default is ₹, and
            // names/addresses routinely carry non-ASCII text.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headings);

            $query->orderBy($keyName)->chunk($chunkSize, function ($rows) use ($out, $rowMapper) {
                foreach ($rows as $row) {
                    fputcsv($out, $rowMapper($row));
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
