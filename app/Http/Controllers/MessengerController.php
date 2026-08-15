<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessengerController extends Controller
{
    /**
     * Список разговоров пользователя с последним сообщением и счётчиком
     * непрочитанных — одним join-запросом на все разговоры разом, без
     * N+1 по каждому (see план: "unread badge — single query").
     */
    public function summary(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $unreadByConversation = DB::table('conversation_participants as cp')
            ->leftJoin('messages as m', function ($join) use ($userId) {
                $join->on('m.conversation_id', '=', 'cp.conversation_id')
                    ->where('m.sender_id', '!=', $userId)
                    ->where(function ($q) {
                        $q->whereNull('cp.last_read_at')
                            ->orWhereColumn('m.created_at', '>', 'cp.last_read_at');
                    });
            })
            ->where('cp.user_id', $userId)
            ->groupBy('cp.conversation_id')
            ->select('cp.conversation_id', DB::raw('COUNT(m.id) as unread'))
            ->get()
            ->keyBy('conversation_id');

        $conversations = Conversation::with(['team:id,name', 'latestMessage.sender:id,name', 'participants:id,name'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->get()
            ->map(function (Conversation $conversation) use ($unreadByConversation, $userId) {
                // У личной переписки нет своего имени — берём из другого участника.
                $directWith = $conversation->type === Conversation::TYPE_DIRECT
                    ? $conversation->participants->firstWhere('id', '!=', $userId)
                    : null;

                return [
                    'id' => $conversation->id,
                    'type' => $conversation->type,
                    'team' => $conversation->team ? [
                        'id' => $conversation->team->id,
                        'name' => $conversation->team->name,
                    ] : null,
                    'direct_with' => $directWith ? [
                        'id' => $directWith->id,
                        'name' => $directWith->name,
                    ] : null,
                    'unread_count' => (int) ($unreadByConversation[$conversation->id]->unread ?? 0),
                    'last_message' => $conversation->latestMessage ? [
                        'id' => $conversation->latestMessage->id,
                        'type' => $conversation->latestMessage->type,
                        'body' => $conversation->latestMessage->body,
                        'sender_name' => $conversation->latestMessage->sender?->name,
                        'created_at' => $conversation->latestMessage->created_at,
                    ] : null,
                ];
            })
            ->values();

        return response()->json([
            'total_unread' => (int) $unreadByConversation->sum('unread'),
            'conversations' => $conversations,
        ]);
    }

    /** Люди, с которыми можно начать личную переписку — с кем разделена хотя бы одна команда, плюс root в обе стороны. */
    public function teammates(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_root) {
            $teammates = User::where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name', 'email']);
        } else {
            $teamIds = $user->teams()->pluck('teams.id');

            $teammates = User::where('id', '!=', $user->id)
                ->where(fn ($q) => $q->where('is_root', true)->orWhereHas(
                    'teams',
                    fn ($q) => $q->whereIn('teams.id', $teamIds),
                ))
                ->orderBy('name')
                ->get(['id', 'name', 'email']);
        }

        return response()->json($teammates);
    }
}
