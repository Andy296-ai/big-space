<?php

use App\Models\Node;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->owner = User::factory()->create();
    $this->space = Space::create(['name' => 'Locking', 'slug' => 'locking-space', 'user_id' => $this->owner->id]);
    $this->node = Node::create(['space_id' => $this->space->id, 'title' => 'Locked node']);
});

test('acquiring a lock on an unlocked node succeeds', function () {
    $this->actingAs($this->owner);

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock")
        ->assertStatus(200)
        ->json();

    expect($response['locked_by']['id'])->toBe($this->owner->id);
    $this->assertDatabaseHas('nodes', ['id' => $this->node->id, 'locked_by' => $this->owner->id]);
});

test('a second user cannot acquire a lock already held within the TTL', function () {
    $other = User::factory()->create();
    $this->space->collaborators()->attach($other->id, ['role' => 'editor']);

    $this->actingAs($this->owner);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock");

    $this->actingAs($other);
    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock");

    $response->assertStatus(409);
    expect($response->json('locked_by.id'))->toBe($this->owner->id);
});

test('the same user can re-acquire their own lock to refresh the TTL', function () {
    $this->actingAs($this->owner);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock");

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock")
        ->assertStatus(200);
});

test('a lock past its TTL can be acquired by someone else', function () {
    $other = User::factory()->create();
    $this->space->collaborators()->attach($other->id, ['role' => 'editor']);

    $this->actingAs($this->owner);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock");

    $this->travel(16)->minutes();

    $this->actingAs($other);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock")
        ->assertStatus(200);

    $this->assertDatabaseHas('nodes', ['id' => $this->node->id, 'locked_by' => $other->id]);
});

test('releasing a lock held by someone else is forbidden for a non-root user', function () {
    $other = User::factory()->create();
    $this->space->collaborators()->attach($other->id, ['role' => 'editor']);

    $this->actingAs($this->owner);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock");

    $this->actingAs($other);
    $this->deleteJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock")
        ->assertStatus(403);
});

test('root can force-release a lock held by someone else', function () {
    $this->actingAs($this->owner);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock");

    $this->actingAs($this->root);
    $this->deleteJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock")
        ->assertStatus(200);

    $this->assertDatabaseHas('nodes', ['id' => $this->node->id, 'locked_by' => null]);
});

test('releasing an already-unlocked node is a harmless no-op', function () {
    $this->actingAs($this->owner);

    $this->deleteJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock")
        ->assertStatus(200);
});

test('saving a node whose lock expired mid-edit is rejected with 409, not silently applied', function () {
    $other = User::factory()->create();
    $this->space->collaborators()->attach($other->id, ['role' => 'editor']);

    $this->actingAs($this->owner);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock");

    // Другой пользователь дожидается истечения TTL и захватывает лок сам —
    // владелец теперь редактирует "вслепую" с уже недействительным локом.
    $this->travel(16)->minutes();

    $this->actingAs($other);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock");

    $this->actingAs($this->owner);
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}", ['title' => 'Clobbered?'])
        ->assertStatus(409);

    expect($this->node->fresh()->title)->toBe('Locked node');
});

test('saving a node the user still legitimately holds the lock on succeeds', function () {
    $this->actingAs($this->owner);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/lock");

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}", ['title' => 'Updated title'])
        ->assertStatus(200);

    expect($this->node->fresh()->title)->toBe('Updated title');
});

test('saving an unlocked node still works normally', function () {
    $this->actingAs($this->owner);

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}", ['title' => 'No lock needed'])
        ->assertStatus(200);
});
