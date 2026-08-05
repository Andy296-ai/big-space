<?php

use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'owner-user']);
    $this->collaborator = User::factory()->create(['name' => 'collab-user']);
    $this->stranger = User::factory()->create(['name' => 'stranger-user']);

    $this->actingAs($this->owner);
    $this->space = Space::create([
        'name' => 'Shared Space',
        'slug' => 'shared-space',
        'user_id' => $this->owner->id,
    ]);
});

test('the owner can share a space with another user as a viewer', function () {
    $response = $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'collab-user',
        'role' => 'viewer',
    ]);

    $response->assertStatus(201)->assertJson(['role' => 'viewer']);
    $this->assertDatabaseHas('space_collaborators', [
        'space_id' => $this->space->id,
        'user_id' => $this->collaborator->id,
        'role' => 'viewer',
    ]);
});

test('sharing accepts an email as the identifier too', function () {
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => $this->collaborator->email,
        'role' => 'editor',
    ])->assertStatus(201);
});

test('a shared viewer can see the graph but not change it', function () {
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'collab-user',
        'role' => 'viewer',
    ]);

    $this->actingAs($this->collaborator);

    $this->getJson("/api/spaces/{$this->space->id}/graph")->assertStatus(200);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'Nope'])
        ->assertStatus(403);
});

test('a shared editor can change the graph', function () {
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'collab-user',
        'role' => 'editor',
    ]);

    $this->actingAs($this->collaborator);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'Yep'])
        ->assertStatus(201);
});

test('a shared editor cannot delete the space or manage sharing', function () {
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'collab-user',
        'role' => 'editor',
    ]);

    $this->actingAs($this->collaborator);

    $this->deleteJson("/api/spaces/{$this->space->id}")->assertStatus(403);
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'stranger-user',
        'role' => 'viewer',
    ])->assertStatus(403);
    $this->assertDatabaseHas('spaces', ['id' => $this->space->id]);
});

test('a stranger with no share cannot access the space at all', function () {
    $this->actingAs($this->stranger);

    $this->getJson("/api/spaces/{$this->space->id}/graph")->assertStatus(403);
});

test('a shared space shows up in the collaborator\'s space list with their role and the owner\'s name', function () {
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'collab-user',
        'role' => 'editor',
    ]);

    $this->actingAs($this->collaborator);
    $list = $this->getJson('/api/spaces')->assertStatus(200)->json();

    $entry = collect($list)->firstWhere('id', $this->space->id);
    expect($entry)->not->toBeNull();
    expect($entry['role'])->toBe('editor');
    expect($entry['owner_name'])->toBe('owner-user');
});

test('the owner can change a collaborator\'s role and revoke access', function () {
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'collab-user',
        'role' => 'viewer',
    ]);

    $this->putJson("/api/spaces/{$this->space->id}/collaborators/{$this->collaborator->id}", [
        'role' => 'editor',
    ])->assertStatus(200);
    $this->assertDatabaseHas('space_collaborators', [
        'user_id' => $this->collaborator->id,
        'role' => 'editor',
    ]);

    $this->deleteJson("/api/spaces/{$this->space->id}/collaborators/{$this->collaborator->id}")
        ->assertStatus(200);
    $this->assertDatabaseMissing('space_collaborators', ['user_id' => $this->collaborator->id]);

    $this->actingAs($this->collaborator);
    $this->getJson("/api/spaces/{$this->space->id}/graph")->assertStatus(403);
});

test('cannot share with an unknown identifier, the owner, or the same user twice', function () {
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'nobody-like-this',
        'role' => 'viewer',
    ])->assertStatus(422);

    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'owner-user',
        'role' => 'viewer',
    ])->assertStatus(422);

    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'collab-user',
        'role' => 'viewer',
    ])->assertStatus(201);

    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'collab-user',
        'role' => 'editor',
    ])->assertStatus(422);
});

test('root can access and edit a shared or unshared space regardless of collaborator status', function () {
    $root = User::where('is_root', true)->firstOrFail();
    $this->actingAs($root);

    $this->getJson("/api/spaces/{$this->space->id}/graph")->assertStatus(200);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'Root can'])
        ->assertStatus(201);
});
