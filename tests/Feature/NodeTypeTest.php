<?php

use App\Models\Node;
use App\Models\NodeType;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->space = Space::create(['name' => 'Types Space', 'slug' => 'types-space', 'user_id' => $this->owner->id]);
    $this->actingAs($this->owner);
});

test('an editor can create a node type with a field schema', function () {
    $response = $this->postJson("/api/spaces/{$this->space->id}/node-types", [
        'key' => 'contact',
        'label' => 'Contact',
        'icon' => 'user',
        'color' => '#3b82f6',
        'field_schema' => [
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
            ['key' => 'photo', 'label' => 'Photo', 'type' => 'photo'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['active', 'archived']],
        ],
    ])->assertStatus(201)->json();

    expect($response['key'])->toBe('contact');
    expect($response['field_schema'])->toHaveCount(3);
    $this->assertDatabaseHas('node_types', ['space_id' => $this->space->id, 'key' => 'contact']);
});

test('the key must be unique within a space but can repeat across spaces', function () {
    NodeType::create(['space_id' => $this->space->id, 'key' => 'contact', 'label' => 'Contact']);

    $this->postJson("/api/spaces/{$this->space->id}/node-types", ['key' => 'contact', 'label' => 'Contact again'])
        ->assertStatus(422);

    $otherSpace = Space::create(['name' => 'Other', 'slug' => 'other-space', 'user_id' => $this->owner->id]);
    $this->postJson("/api/spaces/{$otherSpace->id}/node-types", ['key' => 'contact', 'label' => 'Contact'])
        ->assertStatus(201);
});

test('a select field without options is rejected', function () {
    $this->postJson("/api/spaces/{$this->space->id}/node-types", [
        'key' => 'contact',
        'label' => 'Contact',
        'field_schema' => [
            ['key' => 'status', 'label' => 'Status', 'type' => 'select'],
        ],
    ])->assertStatus(422);
});

test('duplicate field keys within the same schema are rejected', function () {
    $this->postJson("/api/spaces/{$this->space->id}/node-types", [
        'key' => 'contact',
        'label' => 'Contact',
        'field_schema' => [
            ['key' => 'note', 'label' => 'Note', 'type' => 'text'],
            ['key' => 'note', 'label' => 'Another note', 'type' => 'textarea'],
        ],
    ])->assertStatus(422);
});

test('a type cannot have more than one photo field or more than one files field', function () {
    $this->postJson("/api/spaces/{$this->space->id}/node-types", [
        'key' => 'contact',
        'label' => 'Contact',
        'field_schema' => [
            ['key' => 'photo1', 'label' => 'Photo 1', 'type' => 'photo'],
            ['key' => 'photo2', 'label' => 'Photo 2', 'type' => 'photo'],
        ],
    ])->assertStatus(422);
});

test('an unknown field kind is rejected', function () {
    $this->postJson("/api/spaces/{$this->space->id}/node-types", [
        'key' => 'contact',
        'label' => 'Contact',
        'field_schema' => [
            ['key' => 'x', 'label' => 'X', 'type' => 'not_a_real_kind'],
        ],
    ])->assertStatus(422);
});

test('a viewer can list node types but not create one', function () {
    $viewer = User::factory()->create();
    $this->space->collaborators()->attach($viewer->id, ['role' => 'viewer']);
    NodeType::create(['space_id' => $this->space->id, 'key' => 'contact', 'label' => 'Contact']);

    $this->actingAs($viewer);

    $this->getJson("/api/spaces/{$this->space->id}/node-types")->assertStatus(200);
    $this->postJson("/api/spaces/{$this->space->id}/node-types", ['key' => 'x', 'label' => 'X'])->assertStatus(403);
});

test('a node type from another space cannot be updated or deleted through this space', function () {
    $otherOwner = User::factory()->create();
    $otherSpace = Space::create(['name' => 'Other', 'slug' => 'idor-space', 'user_id' => $otherOwner->id]);
    $foreignType = NodeType::create(['space_id' => $otherSpace->id, 'key' => 'secret', 'label' => 'Secret']);

    // $this->owner has no access to $otherSpace at all — can:access,space
    // on the route group should already reject this outright.
    $this->putJson("/api/spaces/{$otherSpace->id}/node-types/{$foreignType->id}", ['key' => 'secret', 'label' => 'Renamed'])
        ->assertStatus(403);

    // Owns $this->space but the type belongs to $otherSpace — must not be
    // reachable by mixing a type id from one space with another space's route.
    $this->putJson("/api/spaces/{$this->space->id}/node-types/{$foreignType->id}", ['key' => 'secret', 'label' => 'Renamed'])
        ->assertStatus(404);
    $this->deleteJson("/api/spaces/{$this->space->id}/node-types/{$foreignType->id}")
        ->assertStatus(404);
});

test('deleting a node type un-types its nodes instead of deleting them', function () {
    $type = NodeType::create(['space_id' => $this->space->id, 'key' => 'contact', 'label' => 'Contact']);
    $node = Node::create([
        'space_id' => $this->space->id,
        'title' => 'Ada',
        'node_type_id' => $type->id,
        'custom_fields' => ['note' => 'hi'],
    ]);

    $this->deleteJson("/api/spaces/{$this->space->id}/node-types/{$type->id}")->assertStatus(200);

    expect($node->fresh()->node_type_id)->toBeNull();
    $this->assertDatabaseHas('nodes', ['id' => $node->id]);
});
