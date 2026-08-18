<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * P0 Customer Core API — the one consistent response envelope for every
 * newly-built/modified Customer endpoint (mission item 7). The pre-existing
 * API (Property/Vehicle/Hotel/PaymentAccount/... controllers) was audited
 * and found to use several different ad-hoc shapes (`{properties:[...]}`,
 * `{reservation:{...}}`, a bare array, `{message, resource}` — see
 * PropertyController/PropertyReservationController/PaymentAccountController
 * for real examples). None of that is touched here — this is a
 * compatibility-safe, additive convention for new Customer Core endpoints
 * only, not a site-wide rewrite (explicitly out of scope per the mission
 * brief).
 *
 * Success: {"success": true, "data": <payload>, "message"?: string, "meta"?: {...}}
 * Error:   {"success": false, "message": string, "errors"?: {...}}   — the
 *          "errors" key, when present, is always keyed by field name to a
 *          list of messages, exactly Laravel's own default validation-error
 *          shape (so a client already handling Laravel's standard 422 body
 *          needs no special case for these routes).
 */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'data' => $data];

        if ($message !== null) {
            $body['message'] = $message;
        }

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    public static function error(string $message, int $status = 422, mixed $errors = null): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }

    /**
     * Same success envelope, with a `meta.pagination` block built from a
     * real `LengthAwarePaginator` — used by every "mine"-style list
     * endpoint (GET /bookings/mine, GET /addresses) so pagination shape is
     * identical across all of them, not reinvented per controller.
     */
    public static function paginated(LengthAwarePaginator|ResourceCollection $paginator, ?string $message = null): JsonResponse
    {
        // ResourceCollection wraps a paginator when constructed via
        // Resource::collection($paginator) — unwrap it to reach the real
        // pagination metadata (current_page/last_page/total/per_page).
        $source = $paginator instanceof ResourceCollection ? $paginator->resource : $paginator;

        return self::success(
            $paginator instanceof ResourceCollection ? $paginator->collection : $paginator->items(),
            $message,
            200,
            ['pagination' => [
                'current_page' => $source->currentPage(),
                'per_page' => $source->perPage(),
                'total' => $source->total(),
                'last_page' => $source->lastPage(),
            ]]
        );
    }
}
