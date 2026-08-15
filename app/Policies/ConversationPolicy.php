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
     * из трёх типов разговора нет read-only участников.
     */
    public function access(User $user, Conversation $conversation): bool
    {
        return $user->is_root
            || $conversation->participants()->where('user_id', $user->id)->exists();
    }
}
