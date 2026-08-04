<?php

namespace App\Policies;

use App\Models\Space;
use App\Models\User;

class SpacePolicy
{
    /** Владелец управляет своим пространством, root — любым (в т.ч. чужими и Admin). */
    public function access(User $user, Space $space): bool
    {
        return $user->is_root || $space->user_id === $user->id;
    }
}
