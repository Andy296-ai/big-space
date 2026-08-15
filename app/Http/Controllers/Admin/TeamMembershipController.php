<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeamMembershipController extends Controller
{
    /** Добавление по логину/email — тот же UX, что и у SpaceCollaboratorController::store(). */
    public function store(Request $request, Team $team, TeamProvisioner $provisioner): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);

        $validated = $request->validate([
            'identifier' => 'required|string|max:255',
        ]);

        $user = User::where('name', $validated['identifier'])
            ->orWhere('email', $validated['identifier'])
            ->first();

        if (! $user) {
            throw ValidationException::withMessages(['identifier' => 'No user with that username or email.']);
        }

        $provisioner->addMember($team, $user);

        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_TEAM_MEMBER_ADDED,
            'user',
            $user->id,
            ['name' => $user->name, 'team' => $team->name],
        );

        return response()->json(['id' => $user->id, 'name' => $user->name, 'email' => $user->email], 201);
    }

    public function destroy(Request $request, Team $team, User $member, TeamProvisioner $provisioner): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);

        $provisioner->removeMember($team, $member);

        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_TEAM_MEMBER_REMOVED,
            'user',
            $member->id,
            ['name' => $member->name, 'team' => $team->name],
        );

        return response()->json(['message' => 'Member removed successfully']);
    }
}
