<?php

use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->author = User::factory()->create();
    $this->global->participants()->attach($this->author->id);
});

test('the author can edit their own text message', function () {
    $this->actingAs($this->author);

    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'oops a typo'])->json();

    $response = $this->putJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}", [
        'body' => 'fixed now',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('body', 'fixed now');
    expect($response->json('edited_at'))->not->toBeNull();

    $this->assertDatabaseHas('messages', ['id' => $created['id'], 'body' => 'fixed now']);
});

test('a stranger cannot edit someone else\'s message, and root has no edit override', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'original'])->json();

    $this->actingAs($this->root);
    $this->putJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}", ['body' => 'rewritten by root'])
        ->assertStatus(403);

    $this->assertDatabaseHas('messages', ['id' => $created['id'], 'body' => 'original']);
});

test('a non-text message cannot be edited', function () {
    $this->actingAs($this->root);
    $message = $this->global->messages()->create(['sender_id' => $this->root->id, 'type' => Message::TYPE_VOICE, 'body' => null]);

    $this->putJson("/api/messenger/conversations/{$this->global->id}/messages/{$message->id}", ['body' => 'text now?'])
        ->assertStatus(422);
});

test('the author or root can delete a message, which becomes a tombstone rather than vanishing', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'delete me'])->json();

    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}")
        ->assertStatus(200);

    $this->assertDatabaseHas('messages', ['id' => $created['id']]);
    $row = Message::find($created['id']);
    expect($row->deleted_at)->not->toBeNull();
    expect($row->body)->toBe('delete me'); // строка не тронута — прячем только на сериализации
});

test('root can delete someone else\'s message', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'root will delete this'])->json();

    $this->actingAs($this->root);
    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}")
        ->assertStatus(200);
});

test('a stranger cannot delete someone else\'s message', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'mine'])->json();

    $stranger = User::factory()->create();
    $this->global->participants()->attach($stranger->id);
    $this->actingAs($stranger);

    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}")
        ->assertStatus(403);
});

test('deleting a message logs it to the tamper-evident activity log', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'audit me'])->json();

    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}");

    $this->assertDatabaseHas('activity_logs', [
        'action' => ActivityLog::ACTION_MESSAGE_DELETED,
        'subject_type' => 'message',
        'subject_id' => $created['id'],
    ]);
});

test('a non-root reader sees a deleted message as a placeholder, but root still sees the real content', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'secret original text'])->json();
    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}");

    $otherUser = User::factory()->create();
    $this->global->participants()->attach($otherUser->id);
    $this->actingAs($otherUser);

    $asOther = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages")->json();
    $entry = collect($asOther)->firstWhere('id', $created['id']);
    expect($entry['body'])->toBeNull();
    expect($entry['deleted_at'])->not->toBeNull();

    $this->actingAs($this->root);
    $asRoot = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages")->json();
    $entryForRoot = collect($asRoot)->firstWhere('id', $created['id']);
    expect($entryForRoot['body'])->toBe('secret original text');
    expect($entryForRoot['deleted_at'])->not->toBeNull();
});

test('a deleted latest message previews as hidden in the summary rail for non-root, but intact for root', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'last words'])->json();
    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}");

    $summaryAsAuthor = $this->getJson('/api/messenger/summary')->json();
    $entry = collect($summaryAsAuthor['conversations'])->firstWhere('id', $this->global->id);
    expect($entry['last_message']['body'])->toBeNull();
    expect($entry['last_message']['deleted_at'])->not->toBeNull();

    $this->actingAs($this->root);
    $summaryAsRoot = $this->getJson('/api/messenger/summary')->json();
    $entryForRoot = collect($summaryAsRoot['conversations'])->firstWhere('id', $this->global->id);
    expect($entryForRoot['last_message']['body'])->toBe('last words');
});

test('an already-deleted message cannot be edited or deleted again', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'gone soon'])->json();
    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}");

    $this->putJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}", ['body' => 'edit after delete'])
        ->assertStatus(404);
    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}")
        ->assertStatus(404);
});
