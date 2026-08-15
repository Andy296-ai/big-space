<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Node;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Обсудить» у узла — находит или создаёт его разговор в мессенджере
 * (type=node, один на узел — см. ConversationPolicy::access для того, как
 * туда пускают: по доступу к пространству узла, не по списку участников).
 */
class NodeDiscussController extends Controller
{
    public function store(Request $request, Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        $conversation = Conversation::firstOrCreate([
            'type' => Conversation::TYPE_NODE,
            'node_id' => $node->id,
        ]);

        // Ленивое участие: сама авторизация идёт через доступ к пространству
        // (ConversationPolicy::access), а участник нужен только для
        // last_read_at/бейджа непрочитанного и чтобы разговор появился в
        // рейле мессенджера у того, кто его открыл.
        if (! $conversation->participants()->where('user_id', $request->user()->id)->exists()) {
            $conversation->participants()->attach($request->user()->id);
        }

        return response()->json(['id' => $conversation->id], 201);
    }
}
