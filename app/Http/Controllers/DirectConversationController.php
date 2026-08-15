<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Личные 1:1 — только между людьми из одной команды (root — исключение в
 * обе стороны: он не состоит в командах, иначе с ним никто не смог бы
 * написать). Проверка — только на СОЗДАНИЕ нового разговора: если общая
 * команда потом исчезнет, уже начатая переписка не блокируется задним
 * числом — так же, как в остальном приложении прошлые события не прячутся
 * из-за более поздних изменений прав (например, записи activity-log про
 * уже отвязанного участника).
 */
class DirectConversationController extends Controller
{
    public function store(Request $request, User $target): JsonResponse
    {
        $user = $request->user();

        if ($target->id === $user->id) {
            throw ValidationException::withMessages(['user_id' => 'cannot_message_self']);
        }

        if (! $user->is_root && ! $target->is_root && ! $this->shareAtLeastOneTeam($user, $target)) {
            throw ValidationException::withMessages(['user_id' => 'not_a_teammate']);
        }

        $conversation = $this->findExisting($user->id, $target->id);

        if ($conversation === null) {
            $conversation = Conversation::create(['type' => Conversation::TYPE_DIRECT]);
            $conversation->participants()->attach([$user->id, $target->id]);
        }

        return response()->json(['id' => $conversation->id], 201);
    }

    private function shareAtLeastOneTeam(User $a, User $b): bool
    {
        return Team::whereHas('users', fn ($q) => $q->where('user_id', $a->id))
            ->whereHas('users', fn ($q) => $q->where('user_id', $b->id))
            ->exists();
    }

    /** Существующий разговор ровно между этими двумя — не больше и не меньше участников. */
    private function findExisting(int $userId, int $targetId): ?Conversation
    {
        return Conversation::where('type', Conversation::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $targetId))
            ->whereDoesntHave('participants', fn ($q) => $q->whereNotIn('user_id', [$userId, $targetId]))
            ->first();
    }
}
