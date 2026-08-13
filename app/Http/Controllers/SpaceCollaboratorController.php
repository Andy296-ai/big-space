<?php

namespace App\Http\Controllers;

use App\Events\NotificationPosted;
use App\Models\ActivityLog;
use App\Models\Space;
use App\Models\SpaceCollaborator;
use App\Models\User;
use App\Notifications\SpaceAccessGranted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Самостоятельный шеринг: владелец пространства сам решает, кому и с какой
 * ролью дать доступ — без участия root. См. Policy::manage для авторизации.
 */
class SpaceCollaboratorController extends Controller
{
    public function index(Space $space): JsonResponse
    {
        return response()->json(
            $space->collaborators()->get(['users.id', 'name', 'email'])
        );
    }

    public function store(Request $request, Space $space): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string|max:255',
            'role' => ['required', Rule::in(SpaceCollaborator::ROLES)],
        ]);

        $user = User::where('name', $validated['identifier'])
            ->orWhere('email', $validated['identifier'])
            ->first();

        if (! $user) {
            throw ValidationException::withMessages(['identifier' => 'No user with that username or email.']);
        }

        if ($user->id === $space->user_id) {
            throw ValidationException::withMessages(['identifier' => 'The owner already has full access.']);
        }

        if ($space->collaborators()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['identifier' => 'This user already has access.']);
        }

        $space->collaborators()->attach($user->id, ['role' => $validated['role']]);

        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_COLLABORATOR_ADDED,
            'user',
            $user->id,
            ['name' => $user->name, 'role' => $validated['role']],
            $space->id,
        );

        $user->notify(new SpaceAccessGranted($space, $validated['role']));
        NotificationPosted::dispatch($user->id);

        return response()->json(
            ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $validated['role']],
            201,
        );
    }

    public function update(Request $request, Space $space, User $collaborator): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(SpaceCollaborator::ROLES)],
        ]);

        $updated = $space->collaborators()->updateExistingPivot($collaborator->id, ['role' => $validated['role']]);
        abort_unless($updated > 0, 404);

        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_COLLABORATOR_ROLE_CHANGED,
            'user',
            $collaborator->id,
            ['name' => $collaborator->name, 'role' => $validated['role']],
            $space->id,
        );

        return response()->json(['message' => 'Role updated successfully']);
    }

    public function destroy(Request $request, Space $space, User $collaborator): JsonResponse
    {
        $space->collaborators()->detach($collaborator->id);

        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_COLLABORATOR_REMOVED,
            'user',
            $collaborator->id,
            ['name' => $collaborator->name],
            $space->id,
        );

        return response()->json(['message' => 'Access revoked successfully']);
    }
}
