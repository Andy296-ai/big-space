<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Смотреть и писать: участник разговора, либо root (root видит все
     * разговоры, включая чужие личные — та же политика доступа, что и у
     * пространств, явно подтверждена для мессенджера отдельно). В отличие
     * от SpacePolicy здесь одна способность, не access/edit: ни у одного
     * из четырёх типов разговора нет read-only участников.
     *
     * Обсуждение узла (type=node) — исключение из "участник или root": туда
     * пускают не по списку участников, а по доступу к пространству узла
     * (та же дисциплина, что и у комментариев к узлу) — список участников
     * там существует только для last_read_at/бейджа непрочитанного, не для
     * авторизации.
     */
    public function access(User $user, Conversation $conversation): bool
    {
        if ($conversation->type === Conversation::TYPE_NODE) {
            return $conversation->node !== null && $conversation->node->space->isAccessibleBy($user);
        }

        return $user->is_root
            || $conversation->participants()->where('user_id', $user->id)->exists();
    }
}
