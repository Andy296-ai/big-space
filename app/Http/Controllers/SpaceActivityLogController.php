<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Лента изменений для владельца пространства — то же самое, что видит root
 * в Admin\ActivityLogController, но отфильтровано по одному пространству.
 * Доступ гарантирует can:manage,space в routes/web.php (владелец либо root).
 */
class SpaceActivityLogController extends Controller
{
    private const PER_PAGE = 100;

    public function index(Request $request, Space $space): JsonResponse
    {
        ActivityLog::record($request->user(), ActivityLog::ACTION_ACTIVITY_LOG_VIEWED, spaceId: $space->id);

        $entries = ActivityLog::with('actor:id,name,email')
            ->where('space_id', $space->id)
            ->latest('created_at')
            ->limit(self::PER_PAGE)
            ->get();

        return response()->json(['entries' => $entries]);
    }
}
