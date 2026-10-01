<?php

namespace App\Support;

use Illuminate\Pagination\AbstractPaginator;

/**
 * Display-only S.No for admin list tables (1CF-ADMIN-SNO-001).
 *
 * Never stored: derived at render time as (current page - 1) x per page +
 * row position, so it continues across pages and always follows whatever
 * filter / tab / search / sort produced the paginator. A plain collection
 * or array (an unpaginated table) simply counts 1..n.
 */
class SerialNumber
{
    public static function offset(mixed $rows): int
    {
        if ($rows instanceof AbstractPaginator) {
            return max(0, ($rows->currentPage() - 1) * $rows->perPage());
        }

        return 0;
    }

    /** @param int $position 1-based position of the row within the displayed page ($loop->iteration) */
    public static function at(mixed $rows, int $position): int
    {
        return self::offset($rows) + $position;
    }
}
