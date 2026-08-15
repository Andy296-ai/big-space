<?php

use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/** Тот же критерий доступа, что и у REST-эндпоинтов пространства (см. SpacePolicy::access). */
Broadcast::channel('space.{spaceId}', function (User $user, int $spaceId) {
    $space = Space::find($spaceId);

    return $space !== null && $space->isAccessibleBy($user);
});

/**
 * Presence-канал: кто сейчас смотрит пространство. Данные, которые здесь
 * возвращаются, долетают до других участников в событии "here"/"joining".
 *
 * @return array{id: int, name: string}|false
 */
Broadcast::channel('space.{spaceId}.presence', function (User $user, int $spaceId) {
    $space = Space::find($spaceId);

    if ($space === null || ! $space->isAccessibleBy($user)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

/**
 * Presence-канал на всё приложение, не на пространство — кто сейчас в
 * сети, для точки online/offline у личных переписок в мессенджере. Любой
 * аутентифицированный пользователь имеет право в нём находиться (в отличие
 * от space.{id}.presence, тут нет отдельного ресурса, к которому проверять
 * доступ — сам факт быть залогиненным им и является).
 *
 * @return array{id: int, name: string}
 */
Broadcast::channel('messenger.presence', function (User $user) {
    return ['id' => $user->id, 'name' => $user->name];
});
