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

        // ->latest('id') как тай-брейк, не только created_at: в Postgres
        // now()/CURRENT_TIMESTAMP — время начала ТРАНЗАКЦИИ, не момента
        // самого INSERT, так что несколько записей лога, созданных подряд
        // внутри одного запроса/транзакции, получают ОДИНАКОВЫЙ created_at
        // побитово — без вторичной сортировки порядок "кто раньше" среди
        // них не определён (SQLite так не делает, поэтому баг был не виден
        // до перехода тестов на настоящий Postgres).
        $entries = ActivityLog::with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
