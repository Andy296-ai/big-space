<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Team;
use App\Services\TeamProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Команды для мессенджера — управляет только root, тот же abort_unless-паттерн, что и Admin\UserController. */
class TeamController extends Controller
{
    /**
     * Полный список участников грузим сразу для всех команд (не постранично
     * и не по запросу за каждую отдельно) — экран управления командами
     * администраторский, команд и участников в нём немного, а без этого
     * разворачивание любой команды в TeamManagerModal.vue стоило бы лишнего
     * запроса.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);

        return response()->json(
            Team::withCount('users')->with('users:id,name,email')->orderBy('name')->get()
        );
    }

    public function store(Request $request, TeamProvisioner $provisioner): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:teams,name',
            'description' => 'nullable|string|max:255',
            'member_ids' => 'nullable|array',
            'member_ids.*' => 'integer|exists:users,id',
        ]);

        $team = $provisioner->createTeam(
            $validated['name'],
            $validated['description'] ?? '',
            $validated['member_ids'] ?? [],
        );

        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_TEAM_CREATED,
            'team',
            $team->id,
            ['name' => $team->name],
        );

        return response()->json($team->load('users:id,name,email'), 201);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:teams,name,'.$team->id,
            'description' => 'nullable|string|max:255',
        ]);

        $team->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? '',
        ]);

        return response()->json($team);
    }

    public function destroy(Request $request, Team $team, TeamProvisioner $provisioner): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);

        $teamId = $team->id;
        $teamName = $team->name;

        $provisioner->deleteTeam($team);

        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_TEAM_DELETED,
            'team',
            $teamId,
            ['name' => $teamName],
        );

        return response()->json(['message' => 'Team deleted successfully']);
    }
}
