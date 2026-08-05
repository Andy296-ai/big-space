<?php

use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/** Тот же критерий доступа, что и у REST-эндпоинтов пространства (см. SpacePolicy). */
Broadcast::channel('space.{spaceId}', function (User $user, int $spaceId) {
    $space = Space::find($spaceId);

    return $space !== null && ($user->is_root || $space->user_id === $user->id);
});
