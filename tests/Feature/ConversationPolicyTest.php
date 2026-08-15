<?php

use App\Models\Conversation;
use App\Models\User;

/**
 * Проверка ConversationPolicy на уровне политики, а не HTTP — контроллеров
 * ещё нет (появятся в следующих фазах мессенджера). Это тот самый
 * IDOR-регрессионный тест "сначала", который в GraphIdorTest.php написан
 * уже поверх реальных эндпоинтов; здесь — поверх политики напрямую, чтобы
 * зафиксировать правило доступа до того, как на него начнут опираться
 * контроллеры.
 */
test('a participant can access their own conversation', function () {
    $user = User::factory()->create();
    $conversation = Conversation::create(['type' => Conversation::TYPE_DIRECT]);
    $conversation->participants()->attach($user->id);

    expect($user->can('access', $conversation))->toBeTrue();
});

test('a non-participant cannot access a conversation they are not in', function () {
    $stranger = User::factory()->create();
    $participant = User::factory()->create();
    $conversation = Conversation::create(['type' => Conversation::TYPE_DIRECT]);
    $conversation->participants()->attach($participant->id);

    expect($stranger->can('access', $conversation))->toBeFalse();
});

test('root can access any conversation, including a DM between two other users', function () {
    $root = User::where('name', config('auth.root.username'))->firstOrFail();
    $a = User::factory()->create();
    $b = User::factory()->create();
    $conversation = Conversation::create(['type' => Conversation::TYPE_DIRECT]);
    $conversation->participants()->attach([$a->id, $b->id]);

    expect($root->can('access', $conversation))->toBeTrue();
});

test('the global conversation seed only covers users that existed at migration time', function () {
    // root уже существует к моменту 2026_08_15_000007 (сеется ранней
    // миграцией) — попадает в общий разговор автоматически.
    $root = User::where('name', config('auth.root.username'))->firstOrFail();
    $global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    expect($root->can('access', $global))->toBeTrue();

    // Пользователь, созданный уже ПОСЛЕ миграций (в теле теста), — нет.
    // Подключение новых пользователей к global — задача следующей фазы
    // (одна строка внутри транзакции Admin\UserController::store), не этой.
    $newcomer = User::factory()->create();
    expect($newcomer->can('access', $global))->toBeFalse();
});
