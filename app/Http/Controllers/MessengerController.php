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
        $isRoot = $request->user()->is_root;

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

        $conversations = Conversation::with([
            'team:id,name',
            'node:id,title,space_id',
            'node.space:id,slug',
            'latestMessage.sender:id,name',
            'participants:id,name',
        ])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->get()
            ->map(function (Conversation $conversation) use ($unreadByConversation, $userId, $isRoot) {
                // У личной переписки нет своего имени — берём из другого участника.
                $directWith = $conversation->type === Conversation::TYPE_DIRECT
                    ? $conversation->participants->firstWhere('id', '!=', $userId)
                    : null;

                $latest = $conversation->latestMessage;
                // Плашка «сообщение удалено» — та же дисциплина видимости,
                // что и в MessageController::serialize(): root видит
                // настоящий текст, остальные только факт удаления.
                $hideLatestBody = $latest !== null && $latest->deleted_at !== null && ! $isRoot;

                return [
                    'id' => $conversation->id,
                    'type' => $conversation->type,
                    'team' => $conversation->team ? [
                        'id' => $conversation->team->id,
                        'name' => $conversation->team->name,
                    ] : null,
                    'node' => $conversation->node ? [
                        'id' => $conversation->node->id,
                        'title' => $conversation->node->title,
                        'space_id' => $conversation->node->space_id,
                        'space_slug' => $conversation->node->space->slug,
                    ] : null,
                    'direct_with' => $directWith ? [
                        'id' => $directWith->id,
                        'name' => $directWith->name,
                    ] : null,
                    'unread_count' => (int) ($unreadByConversation[$conversation->id]->unread ?? 0),
                    'last_message' => $latest ? [
                        'id' => $latest->id,
                        'type' => $latest->type,
                        'body' => $hideLatestBody ? null : $latest->body,
                        'sender_name' => $latest->sender?->name,
                        'created_at' => $latest->created_at,
                        'deleted_at' => $latest->deleted_at,
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

    /** Список участников разговора — источник кандидатов для @упоминания в композере. */
    public function participants(Request $request, Conversation $conversation): JsonResponse
    {
        return response()->json(
            $conversation->participants()->orderBy('users.name')->get(['users.id', 'users.name']),
        );
    }
}
