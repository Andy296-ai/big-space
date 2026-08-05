<?php

use App\Models\Space;
use App\Models\User;
use Tests\TestCase;

/**
 * BROADCAST_CONNECTION в phpunit.xml — "null", а его драйвер не вызывает
 * авторизацию каналов вообще (см. NullBroadcaster::auth() — пустая
 * реализация), так что /broadcasting/auth всегда отвечает 200. Чтобы
 * действительно прогнать routes/channels.php, здесь временно переключаемся
 * на "reverb" (= PusherBroadcaster) — подпись считается локально по HMAC,
 * реальный сервер Reverb для этого не нужен.
 */
beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    // routes/channels.php уже выполнился при загрузке приложения и
    // зарегистрировал каналы на драйвере, который был дефолтным ТОГДА
    // (null из phpunit.xml). Переключение конфига само по себе не переносит
    // регистрации на новый инстанс — прогоняем файл ещё раз, теперь уже
    // под "reverb", чтобы каналы реально были на ком проверять авторизацию.
    require base_path('routes/channels.php');

    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->space = Space::create([
        'name' => 'Broadcast Space',
        'slug' => 'broadcast-space',
        'user_id' => $this->owner->id,
    ]);
});

/** socket_id — формальность для подписи Pusher-протокола, реальным сокетом не проверяется. */
function authChannel(TestCase $test, string $channelName)
{
    return $test->postJson('/broadcasting/auth', [
        'channel_name' => $channelName,
        'socket_id' => '1234.5678',
    ]);
}

test('the owner can authenticate the live-sync and presence channels', function () {
    authChannel($this, "private-space.{$this->space->id}")->assertStatus(200);
    authChannel($this, "presence-space.{$this->space->id}.presence")->assertStatus(200);
});

test('a shared viewer or editor can authenticate both channels — regression for the sharing rollout', function () {
    $collaborator = User::factory()->create();
    $this->space->collaborators()->attach($collaborator->id, ['role' => 'viewer']);

    $this->actingAs($collaborator);

    authChannel($this, "private-space.{$this->space->id}")->assertStatus(200);
    authChannel($this, "presence-space.{$this->space->id}.presence")->assertStatus(200);
});

test('a stranger with no access cannot authenticate either channel', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    authChannel($this, "private-space.{$this->space->id}")->assertStatus(403);
    authChannel($this, "presence-space.{$this->space->id}.presence")->assertStatus(403);
});

test('root can authenticate channels for any space, including one it does not own', function () {
    $root = User::where('is_root', true)->firstOrFail();
    $this->actingAs($root);

    authChannel($this, "private-space.{$this->space->id}")->assertStatus(200);
});
