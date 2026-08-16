<?php

namespace App\Http\Controllers;

use App\Events\NodeLockChanged;
use App\Models\Node;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Жёсткая блокировка узла на время редактирования — EditNodeModal.vue сам
 * захватывает лок на mount и отпускает на unmount, см. план "Node
 * edit-locking — hard block". TTL — Node::LOCK_TTL_MINUTES, страховка от
 * забытой открытой вкладки без heartbeat.
 */
class NodeLockController extends Controller
{
    /** Захват/продление — тот же пользователь может повторно захватывать (две вкладки), TTL просто обновляется. */
    public function store(Request $request, Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        $user = $request->user();

        if ($node->isLockedByOther($user)) {
            return response()->json([
                'message' => 'This node is currently being edited by someone else.',
                'locked_by' => ['id' => $node->lockedBy->id, 'name' => $node->lockedBy->name],
            ], 409);
        }

        // Query-builder, не $node->update() — тот триггернул бы Node::booted()'s
        // SpaceUpdated (полный refetch графа) на каждый захват/продление лока.
        Node::where('id', $node->id)->update(['locked_by' => $user->id, 'locked_at' => now()]);

        NodeLockChanged::dispatch($space->id, $node->id, ['id' => $user->id, 'name' => $user->name]);

        return response()->json(['locked_by' => ['id' => $user->id, 'name' => $user->name]]);
    }

    /** Снятие — держатель лока или root (форс-анлок, тот же прецедент "root управляет всем"). Снятие уже отсутствующего лока — не ошибка. */
    public function destroy(Request $request, Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        $user = $request->user();
        abort_unless($node->locked_by === null || $node->locked_by === $user->id || $user->is_root, 403);

        Node::where('id', $node->id)->update(['locked_by' => null, 'locked_at' => null]);

        NodeLockChanged::dispatch($space->id, $node->id, null);

        return response()->json(['message' => 'ok']);
    }
}
