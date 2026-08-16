<?php

use App\Models\Conversation;
use App\Models\User;

beforeEach(function () {
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->author = User::factory()->create();
    $this->reader = User::factory()->create();
    $this->global->participants()->attach([$this->author->id, $this->reader->id]);
});

test('read-by lists a participant who marked the conversation read after the message was sent', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'hello'])->json();

    $this->actingAs($this->reader);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/read");

    $this->actingAs($this->author);
    $readers = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/read-by")
        ->assertStatus(200)
        ->json();

    expect(collect($readers)->pluck('id'))->toContain($this->reader->id);
    // Отправитель никогда не фигурирует в списке прочитавших собственное сообщение.
    expect(collect($readers)->pluck('id'))->not->toContain($this->author->id);
});

test('read-by excludes a participant whose last read predates the message', function () {
    $this->actingAs($this->reader);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/read");

    // Разносим по времени явно — иначе last_read_at и created_at в тесте
    // могут округлиться до одной и той же секунды (колонки без микросекунд)
    // и сравнение ">=" ложно сочтёт это "прочитано после отправки".
    $this->travel(2)->seconds();

    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'hello again'])->json();

    $readers = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/read-by")
        ->assertStatus(200)
        ->json();

    expect(collect($readers)->pluck('id'))->not->toContain($this->reader->id);
});
