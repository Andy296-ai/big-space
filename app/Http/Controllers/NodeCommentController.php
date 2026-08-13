<?php

namespace App\Http\Controllers;

use App\Events\CommentPosted;
use App\Models\Node;
use App\Models\NodeComment;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Обсуждение прямо на узле — доступно на чтение/запись всем, у кого есть
 * доступ к пространству (viewer в том числе, как в Google Docs), удаление —
 * автору или владельцу/root.
 */
class NodeCommentController extends Controller
{
    public function index(Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        $comments = $node->comments()
            ->with('user:id,name')
            ->oldest('created_at')
            ->get();

        return response()->json($comments);
    }

    public function store(Request $request, Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $comment = $node->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        CommentPosted::dispatch($space->id, $node->id);

        return response()->json($comment->load('user:id,name'), 201);
    }

    public function destroy(Request $request, Space $space, Node $node, NodeComment $comment): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);
        abort_unless($comment->node_id === $node->id, 404);

        $user = $request->user();
        $canModerate = $user->is_root || $space->user_id === $user->id;
        abort_unless($comment->user_id === $user->id || $canModerate, 403);

        $comment->delete();

        CommentPosted::dispatch($space->id, $node->id);

        return response()->json(['message' => 'Comment deleted successfully']);
    }
}
