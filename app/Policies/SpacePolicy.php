<?php

namespace App\Policies;

use App\Models\Space;
use App\Models\User;

class SpacePolicy
{
    /** Смотреть: владелец, root, или любой участник — как viewer, так и editor. */
    public function access(User $user, Space $space): bool
    {
        return $space->isAccessibleBy($user);
    }

    /** Менять граф: владелец, root, или участник с ролью editor. */
    public function edit(User $user, Space $space): bool
    {
        return $space->isEditableBy($user);
    }

    /** Управлять расшариванием (кому и с какой ролью) и удалять пространство: только владелец или root. */
    public function manage(User $user, Space $space): bool
    {
        return $user->is_root || $space->user_id === $user->id;
    }
}
