<?php

use App\Models\Node;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->editor = User::factory()->create();
    $this->viewer = User::factory()->create();

    $this->actingAs($this->owner);
    $this->space = Space::create(['name' => 'Trees', 'slug' => 'trees-space', 'user_id' => $this->owner->id]);
    $this->space->collaborators()->attach($this->editor->id, ['role' => 'editor']);
    $this->space->collaborators()->attach($this->viewer->id, ['role' => 'viewer']);

    $this->root = Node::create(['space_id' => $this->space->id, 'title' => 'Root', 'depth' => 0]);
    $this->root->update(['tree_root_id' => $this->root->id]);

    $this->child = Node::create([
        'space_id' => $this->space->id,
        'title' => 'Child',
        'depth' => 1,
        'tree_root_id' => $this->root->id,
        'shape' => 'circle',
        'color' => '#111111',
    ]);
});

test('an editor can save default shape/color on the root node', function () {
    $this->actingAs($this->editor);

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->root->id}/tree-settings", [
        'default_shape' => 'hexagon',
        'default_color' => '#abcdef',
    ])->assertStatus(200)
        ->assertJsonPath('default_shape', 'hexagon')
        ->assertJsonPath('default_color', '#abcdef');

    $this->assertDatabaseHas('nodes', [
        'id' => $this->root->id,
        'default_shape' => 'hexagon',
        'default_color' => '#abcdef',
    ]);

    // Saving defaults alone must not touch existing nodes' own shape/color.
    $this->assertDatabaseHas('nodes', [
        'id' => $this->child->id,
        'shape' => 'circle',
        'color' => '#111111',
    ]);
});

test('a viewer cannot change tree settings', function () {
    $this->actingAs($this->viewer);

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->root->id}/tree-settings", [
        'default_shape' => 'square',
    ])->assertStatus(403);
});

test('tree settings are rejected on a non-root node', function () {
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->child->id}/tree-settings", [
        'default_shape' => 'square',
    ])->assertStatus(422);
});

test('an invalid shape is rejected', function () {
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->root->id}/tree-settings", [
        'default_shape' => 'not-a-real-shape',
    ])->assertStatus(422);
});

test('apply_to_all cascades the saved defaults onto every node of this tree, including the root', function () {
    $grandchild = Node::create([
        'space_id' => $this->space->id,
        'title' => 'Grandchild',
        'depth' => 2,
        'tree_root_id' => $this->root->id,
        'shape' => 'circle',
        'color' => '#222222',
    ]);

    $otherTreeRoot = Node::create(['space_id' => $this->space->id, 'title' => 'Other root', 'depth' => 0]);
    $otherTreeRoot->update(['tree_root_id' => $otherTreeRoot->id, 'shape' => 'circle', 'color' => '#333333']);

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->root->id}/tree-settings", [
        'default_shape' => 'diamond',
        'default_color' => '#ff00ff',
        'apply_to_all' => true,
    ])->assertStatus(200);

    $this->assertDatabaseHas('nodes', ['id' => $this->root->id, 'shape' => 'diamond', 'color' => '#ff00ff']);
    $this->assertDatabaseHas('nodes', ['id' => $this->child->id, 'shape' => 'diamond', 'color' => '#ff00ff']);
    $this->assertDatabaseHas('nodes', ['id' => $grandchild->id, 'shape' => 'diamond', 'color' => '#ff00ff']);

    // A different tree in the same space must be left untouched.
    $this->assertDatabaseHas('nodes', ['id' => $otherTreeRoot->id, 'shape' => 'circle', 'color' => '#333333']);
});

test('adding a child inherits the tree default shape/color when not explicitly given', function () {
    $this->root->update(['default_shape' => 'triangle', 'default_color' => '#00ff00']);

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->root->id}/child", [
        'title' => 'Inherited child',
    ])->assertStatus(201);

    expect($response->json('node.shape'))->toBe('triangle');
    expect($response->json('node.color'))->toBe('#00ff00');
});

test('adding a child with an explicit shape/color overrides the tree default', function () {
    $this->root->update(['default_shape' => 'triangle', 'default_color' => '#00ff00']);

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->root->id}/child", [
        'title' => 'Explicit child',
        'shape' => 'square',
        'color' => '#0000ff',
    ])->assertStatus(201);

    expect($response->json('node.shape'))->toBe('square');
    expect($response->json('node.color'))->toBe('#0000ff');
});
