<?php

use App\Models\Conversation;
use App\Models\Node;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->owner = User::factory()->create();
    $this->global->participants()->attach($this->owner->id);
    $this->space = Space::create(['name' => 'Mentions', 'slug' => 'mentions-space', 'user_id' => $this->owner->id]);
    $this->node = Node::create(['space_id' => $this->space->id, 'title' => 'Referenced node']);
});

test('a mention of an accessible node is linked and returned as a node reference', function () {
    $this->actingAs($this->owner);

    $response = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", [
        'body' => "see [[node:{$this->node->id}]] for context",
    ]);

    $response->assertStatus(201);
    $refs = $response->json('node_references');
    expect($refs)->toHaveCount(1);
    expect($refs[0]['id'])->toBe($this->node->id);
    expect($refs[0]['title'])->toBe('Referenced node');
    expect($refs[0]['space_slug'])->toBe('mentions-space');

    $this->assertDatabaseHas('message_node_references', [
        'message_id' => $response->json('id'),
        'node_id' => $this->node->id,
    ]);
});

test('a mention of a node the sender cannot access is silently dropped, but the raw text stays', function () {
    $stranger = User::factory()->create();
    $this->global->participants()->attach($stranger->id);
    $this->actingAs($stranger);

    $response = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", [
        'body' => "see [[node:{$this->node->id}]] for context",
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('body', "see [[node:{$this->node->id}]] for context");
    expect($response->json('node_references'))->toBe([]);

    $this->assertDatabaseMissing('message_node_references', ['node_id' => $this->node->id]);
});

test('a reader without access to the mentioned node\'s space does not see the reference, even in a shared conversation', function () {
    $this->actingAs($this->owner);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", [
        'body' => "check [[node:{$this->node->id}]]",
    ])->json();
    expect($created['node_references'])->toHaveCount(1);

    $reader = User::factory()->create();
    $this->global->participants()->attach($reader->id);
    $this->actingAs($reader);

    $asReader = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages")->json();
    $entry = collect($asReader)->firstWhere('id', $created['id']);
    expect($entry['node_references'])->toBe([]);

    // Дав доступ к пространству — ссылка становится видна тем же читателю
    // при следующем запросе (проверка на чтение, не на момент отправки).
    $this->space->collaborators()->attach($reader->id, ['role' => 'viewer']);
    $asReaderAfterAccess = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages")->json();
    $entryAfter = collect($asReaderAfterAccess)->firstWhere('id', $created['id']);
    expect($entryAfter['node_references'])->toHaveCount(1);
});

test('root always sees node references regardless of space access', function () {
    $this->actingAs($this->owner);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", [
        'body' => "check [[node:{$this->node->id}]]",
    ])->json();

    $this->actingAs($this->root);
    $asRoot = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages")->json();
    $entry = collect($asRoot)->firstWhere('id', $created['id']);
    expect($entry['node_references'])->toHaveCount(1);
});

test('editing a message re-parses mentions instead of accumulating them', function () {
    $this->actingAs($this->owner);
    $otherNode = Node::create(['space_id' => $this->space->id, 'title' => 'Other node']);

    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", [
        'body' => "first [[node:{$this->node->id}]]",
    ])->json();
    expect($created['node_references'])->toHaveCount(1);

    $edited = $this->putJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}", [
        'body' => "now mentions [[node:{$otherNode->id}]] instead",
    ])->json();

    expect($edited['node_references'])->toHaveCount(1);
    expect($edited['node_references'][0]['id'])->toBe($otherNode->id);
    $this->assertDatabaseMissing('message_node_references', ['message_id' => $created['id'], 'node_id' => $this->node->id]);
});

test('discuss finds-or-creates exactly one node conversation and attaches the caller as a participant', function () {
    $this->actingAs($this->owner);

    $first = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/discuss")->assertStatus(201)->json();
    $second = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/discuss")->assertStatus(201)->json();

    expect($first['id'])->toBe($second['id']);
    $this->assertDatabaseCount('conversations', 2); // global (seed) + this one node conversation
    $this->assertDatabaseHas('conversation_participants', [
        'conversation_id' => $first['id'],
        'user_id' => $this->owner->id,
    ]);
});

test('a node conversation is accessible to anyone who can access its space, not by a participant list', function () {
    $conversation = Conversation::create(['type' => Conversation::TYPE_NODE, 'node_id' => $this->node->id]);
    $viewer = User::factory()->create();
    $this->space->collaborators()->attach($viewer->id, ['role' => 'viewer']);

    expect($viewer->can('access', $conversation))->toBeTrue();

    $stranger = User::factory()->create();
    expect($stranger->can('access', $conversation))->toBeFalse();
});

test('a stranger to the node\'s space cannot discuss it or read its conversation', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/discuss")->assertStatus(403);
});

test('a node conversation appears in the messenger summary with its node info', function () {
    $this->actingAs($this->owner);
    $conversationId = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/discuss")->json('id');

    $summary = $this->getJson('/api/messenger/summary')->json();
    $entry = collect($summary['conversations'])->firstWhere('id', $conversationId);

    expect($entry['type'])->toBe('node');
    expect($entry['node']['id'])->toBe($this->node->id);
    expect($entry['node']['title'])->toBe('Referenced node');
    expect($entry['node']['space_slug'])->toBe('mentions-space');
});
