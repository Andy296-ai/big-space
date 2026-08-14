<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    private const PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);

        ActivityLog::record($request->user(), ActivityLog::ACTION_ACTIVITY_LOG_VIEWED);

        $entries = ActivityLog::with('actor:id,name,email')
            ->latest('created_at')
            ->limit(self::PER_PAGE)
            ->get();

        return response()->json(['entries' => $entries]);
    }

    /** Root-only, on-demand — не встроено в index(), пересчёт всей цепочки на каждый просмотр списка был бы O(n). */
    public function verify(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);

        $brokenAt = ActivityLog::verifyChain();

        return response()->json([
            'intact' => $brokenAt === null,
            'broken_at_id' => $brokenAt?->id,
        ]);
    }
}
