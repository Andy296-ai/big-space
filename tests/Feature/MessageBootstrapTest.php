<?php

use App\Models\Conversation;
use App\Models\User;

beforeEach(function () {
    $this->author = User::factory()->create();
    $this->reader = User::factory()->create();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->global->participants()->attach([$this->author->id, $this->reader->id]);
});

test('bootstrap returns messages, pinned, participants and read-by in one response', function () {
    $this->actingAs($this->author);
    $first = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'hello'])->json();
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$first['id']}/pin");

    $this->actingAs($this->reader);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/read");

    // Разносим по времени явно — иначе last_read_at и created_at второго
    // сообщения могут округлиться до одной и той же секунды (колонки без
    // микросекунд) и сравнение ">=" в readBy ложно сочтёт это "прочитано".
    $this->travel(2)->seconds();

    $this->actingAs($this->author);
    $second = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'second'])->json();

    $response = $this->getJson("/api/messenger/conversations/{$this->global->id}/bootstrap")
        ->assertStatus(200)
        ->json();

    expect(collect($response['messages'])->pluck('id'))->toContain($first['id'], $second['id']);
    expect(collect($response['pinned'])->pluck('id')->all())->toBe([$first['id']]);
    expect(collect($response['participants'])->pluck('id'))->toContain($this->author->id, $this->reader->id);
    // reader отметил разговор прочитанным ДО второго сообщения — под последним своим (вторым) читателей ещё нет.
    expect($response['read_by'])->toBe([]);
});

test('bootstrap marks the conversation as read as a side effect, same as the dedicated read endpoint', function () {
    $this->actingAs($this->author);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'hi']);

    $this->actingAs($this->reader);
    $this->getJson("/api/messenger/conversations/{$this->global->id}/bootstrap")->assertStatus(200);

    $summary = $this->getJson('/api/messenger/summary')->json();
    $conversation = collect($summary['conversations'])->firstWhere('id', $this->global->id);
    expect($conversation['unread_count'])->toBe(0);
});

test('bootstrap requires access to the conversation', function () {
    $outsider = User::factory()->create();
    $direct = Conversation::create(['type' => Conversation::TYPE_DIRECT]);
    $direct->participants()->attach([$this->author->id, $this->reader->id]);

    $this->actingAs($outsider);
    $this->getJson("/api/messenger/conversations/{$direct->id}/bootstrap")->assertStatus(403);
});
