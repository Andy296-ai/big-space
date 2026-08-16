<?php

use App\Models\Conversation;
use App\Models\User;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->author = User::factory()->create();
    $this->global->participants()->attach($this->author->id);
});

test('any participant can pin and unpin a message, not just its author', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'pin me'])->json();

    $this->actingAs($this->root);
    $pinned = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/pin")
        ->assertStatus(200)
        ->json();
    expect($pinned['pinned_at'])->not->toBeNull();

    $unpinned = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/pin")
        ->assertStatus(200)
        ->json();
    expect($unpinned['pinned_at'])->toBeNull();
});

test('pinned messages are listed separately from cursor pagination', function () {
    $this->actingAs($this->author);
    $old = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'old pinned'])->json();

    // Заполняем страницу свежими сообщениями, чтобы старое ушло за пределы курсора.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => "filler {$i}"]);
    }

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$old['id']}/pin");

    $pinnedList = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages/pinned")->json();
    expect($pinnedList)->toHaveCount(1);
    expect($pinnedList[0]['id'])->toBe($old['id']);
});

test('deleting a pinned message unpins it', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'temp'])->json();
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/pin");

    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}");

    $pinnedList = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages/pinned")->json();
    expect($pinnedList)->toHaveCount(0);
});

test('a deleted message cannot be pinned', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'temp'])->json();
    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}");

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/pin")
        ->assertStatus(404);
});
