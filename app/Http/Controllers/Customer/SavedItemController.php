<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Support\SavedItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ♡ Like / Save (owner 2026-11-07). */
class SavedItemController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $item = SavedItems::open((string) $request->input('ticket'));
        abort_if($item === null, 422);

        return response()->json(['saved' => SavedItems::toggle($request->user(), $item)]);
    }
}
