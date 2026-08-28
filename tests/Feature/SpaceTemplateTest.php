<?php

use App\Models\Node;
use App\Models\NodeType;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('creating a space with a template seeds its node types and starter roots', function () {
    $response = $this->postJson('/api/spaces', [
        'name' => 'Job hunt',
        'template' => 'job_search',
    ])->assertStatus(201)->json();

    $space = Space::findOrFail($response['id']);

    expect(NodeType::where('space_id', $space->id)->pluck('key')->sort()->values()->all())
        ->toBe(['contact', 'platform', 'region']);

    // "Origin" всегда создаётся — шаблон добавляет корни рядом, не заменяет его.
    $origin = Node::where('space_id', $space->id)->where('title', 'Origin')->firstOrFail();
    expect($origin->tree_root_id)->toBe($origin->id);
    expect($origin->node_type_id)->toBeNull();

    $platformType = NodeType::where('space_id', $space->id)->where('key', 'platform')->firstOrFail();
    $platformsRoot = Node::where('space_id', $space->id)->where('title', 'Platforms')->firstOrFail();
    expect($platformsRoot->node_type_id)->toBe($platformType->id);
    expect($platformsRoot->tree_root_id)->toBe($platformsRoot->id);
});

test('creating a space without a template behaves exactly as before', function () {
    $response = $this->postJson('/api/spaces', ['name' => 'Plain'])
        ->assertStatus(201)->json();

    $space = Space::findOrFail($response['id']);

    expect(NodeType::where('space_id', $space->id)->count())->toBe(0);
    expect(Node::where('space_id', $space->id)->count())->toBe(1);
});

test('an unknown template key is rejected', function () {
    $this->postJson('/api/spaces', ['name' => 'Bad', 'template' => 'not_a_real_template'])
        ->assertStatus(422);
});
