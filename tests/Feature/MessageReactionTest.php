<?php

use App\Models\Conversation;
use App\Models\User;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->author = User::factory()->create();
    $this->other = User::factory()->create();
    $this->global->participants()->attach([$this->author->id, $this->other->id]);
});

test('reacting toggles the reaction and reflects the count and reacted_by_me flag', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'hi'])->json();

    $reacted = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/reactions", ['emoji' => '👍'])
        ->assertStatus(200)
        ->json();
    expect($reacted['reactions'])->toHaveCount(1);
    expect($reacted['reactions'][0]['emoji'])->toBe('👍');
    expect($reacted['reactions'][0]['count'])->toBe(1);
    expect($reacted['reactions'][0]['reacted_by_me'])->toBeTrue();

    $unreacted = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/reactions", ['emoji' => '👍'])
        ->assertStatus(200)
        ->json();
    expect($unreacted['reactions'])->toHaveCount(0);
});

test('two different users reacting with the same emoji count separately', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'hi'])->json();
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/reactions", ['emoji' => '🎉']);

    $this->actingAs($this->other);
    $result = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/reactions", ['emoji' => '🎉'])
        ->assertStatus(200)
        ->json();

    expect($result['reactions'])->toHaveCount(1);
    expect($result['reactions'][0]['count'])->toBe(2);
    expect($result['reactions'][0]['reacted_by_me'])->toBeTrue();
});

test('an arbitrary emoji outside the fixed allowlist is rejected', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'hi'])->json();

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/reactions", ['emoji' => '💩'])
        ->assertStatus(422);
});

test('a deleted message cannot be reacted to', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'temp'])->json();
    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}");

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/reactions", ['emoji' => '👍'])
        ->assertStatus(404);
});
