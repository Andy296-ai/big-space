<?php

use App\Models\Conversation;
use App\Models\Node;
use App\Models\Space;
use App\Models\User;
use App\Notifications\UserMentioned;

beforeEach(function () {
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->author = User::factory()->create();
    $this->mentioned = User::factory()->create();
    $this->global->participants()->attach([$this->author->id, $this->mentioned->id]);
});

test('mentioning a conversation participant resolves it as a user reference and notifies them', function () {
    $this->actingAs($this->author);

    $response = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", [
        'body' => "hey [[user:{$this->mentioned->id}]] check this out",
    ]);

    $response->assertStatus(201);
    $mentions = $response->json('user_mentions');
    expect($mentions)->toHaveCount(1);
    expect($mentions[0]['id'])->toBe($this->mentioned->id);
    expect($mentions[0]['name'])->toBe($this->mentioned->name);

    expect($this->mentioned->fresh()->unreadNotifications()->count())->toBe(1);
    $notification = $this->mentioned->fresh()->notifications()->first();
    expect($notification->type)->toBe(UserMentioned::class);
    expect($notification->data['context_type'])->toBe('message');
    expect($notification->data['conversation_id'])->toBe($this->global->id);
});

test('mentioning someone who is not a participant of the conversation is silently dropped', function () {
    $outsider = User::factory()->create();
    $this->actingAs($this->author);

    $response = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", [
        'body' => "hey [[user:{$outsider->id}]]",
    ]);

    $response->assertStatus(201);
    expect($response->json('user_mentions'))->toBe([]);
    expect($outsider->fresh()->unreadNotifications()->count())->toBe(0);
});

test('mentioning yourself does not send a notification', function () {
    $this->actingAs($this->author);

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", [
        'body' => "note to self [[user:{$this->author->id}]]",
    ])->assertStatus(201);

    expect($this->author->fresh()->unreadNotifications()->count())->toBe(0);
});

test('editing a message does not re-fire mention notifications', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'plain message'])->json();

    $this->putJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}", [
        'body' => "edited to mention [[user:{$this->mentioned->id}]]",
    ])->assertStatus(200);

    expect($this->mentioned->fresh()->unreadNotifications()->count())->toBe(0);
});

test('a mentioned participant list is fetchable for the composer autocomplete', function () {
    $this->actingAs($this->author);

    $names = $this->getJson("/api/messenger/conversations/{$this->global->id}/participants")
        ->assertStatus(200)
        ->json();

    expect(collect($names)->pluck('id'))->toContain($this->mentioned->id);
});

test('mentioning a space collaborator in a node comment notifies them, but a non-collaborator is dropped', function () {
    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $outsider = User::factory()->create();

    $space = Space::create(['name' => 'Mentions', 'slug' => 'mentions-comments', 'user_id' => $owner->id]);
    $space->collaborators()->attach($collaborator->id, ['role' => 'viewer']);
    $node = Node::create(['space_id' => $space->id, 'title' => 'Discussed node']);

    $this->actingAs($owner);
    $response = $this->postJson("/api/spaces/{$space->id}/nodes/{$node->id}/comments", [
        'body' => "cc [[user:{$collaborator->id}]] and [[user:{$outsider->id}]]",
    ]);

    $response->assertStatus(201);
    $mentions = $response->json('user_mentions');
    expect(collect($mentions)->pluck('id')->values()->all())->toBe([$collaborator->id]);

    expect($collaborator->fresh()->unreadNotifications()->count())->toBe(1);
    expect($outsider->fresh()->unreadNotifications()->count())->toBe(0);
});

test('the mentionable-users endpoint for a space lists the owner and collaborators', function () {
    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $space = Space::create(['name' => 'Candidates', 'slug' => 'mentions-candidates', 'user_id' => $owner->id]);
    $space->collaborators()->attach($collaborator->id, ['role' => 'editor']);

    $this->actingAs($owner);
    $names = $this->getJson("/api/spaces/{$space->id}/mentionable-users")
        ->assertStatus(200)
        ->json();

    expect(collect($names)->pluck('id'))->toContain($owner->id, $collaborator->id);
});
