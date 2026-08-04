<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Space;
use App\Models\User;
use App\Services\GraphRepository;
use App\Services\LayoutEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Пользователи системы существуют как узлы в единственном Admin-пространстве
 * (см. App\Services\SpaceProvisioner) — управлять ими может только root.
 */
class UserController extends Controller
{
    private static function adminSpace(): Space
    {
        return Space::where('is_admin', true)->firstOrFail();
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,name',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'string', 'min:8'],
            'color' => 'nullable|string|max:32',
            'shape' => ['nullable', Rule::in(Node::SHAPES)],
            'tags' => 'nullable|string|max:255',
        ]);

        $space = self::adminSpace();

        $result = DB::transaction(function () use ($validated, $space) {
            $user = User::create([
                'name' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Узлы пользователей — все корневые (без родителя) в Admin-пространстве.
            $rootCount = Node::where('space_id', $space->id)->where('depth', 0)->count();
            $pos = LayoutEngine::escapeOffset($rootCount, 150.0);

            $node = Node::create([
                'space_id' => $space->id,
                'title' => $validated['name'],
                'description' => '',
                'pos_x' => $pos['x'],
                'pos_y' => $pos['y'],
                'pos_z' => 0,
                'depth' => 0,
                'color' => $validated['color'] ?? '',
                'shape' => $validated['shape'] ?? 'circle',
                'tags' => $validated['tags'] ?? '',
                'linked_user_id' => $user->id,
            ]);
            $node->update(['tree_root_id' => $node->id]);

            return [$user, $node];
        });

        [$user, $node] = $result;

        return response()->json(['user' => $user, 'node' => $node], 201);
    }

    /** Удаляет учётку и её узел (вместе с узлами-пространствами под ним). Личные пространства пользователя остаются, но теряют владельца. */
    public function destroy(Request $request, User $user, GraphRepository $graphRepo): JsonResponse
    {
        abort_unless($request->user()->is_root, 403);
        abort_if($user->is_root, 403, 'The root user cannot be deleted.');

        $node = $user->linkedNode()->first();

        if ($node !== null) {
            $deletionIds = $graphRepo->computeDeletionSetForSpace(self::adminSpace(), [$node->id]);
            Node::whereIn('id', $deletionIds)->delete();
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}
