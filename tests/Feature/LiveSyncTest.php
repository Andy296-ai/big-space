<?php

use App\Events\SpaceUpdated;
use App\Models\Node;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
    $this->space = Space::create(['name' => 'Live', 'slug' => 'live-sync']);
    $this->root = Node::create(['space_id' => $this->space->id, 'title' => 'Root']);
});

test('creating a root node signals the space', function () {
    Event::fake([SpaceUpdated::class]);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'New root'])
        ->assertStatus(201);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);
});

test('adding a child signals the space', function () {
    Event::fake([SpaceUpdated::class]);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->root->id}/child", ['title' => 'Child'])
        ->assertStatus(201);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);
});

test('moving a single node signals the space', function () {
    Event::fake([SpaceUpdated::class]);

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->root->id}/move", [
        'pos_x' => 10,
        'pos_y' => 20,
    ])->assertStatus(200);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);
});

test('bulk-moving nodes signals the space even though it bypasses Eloquent events', function () {
    $second = Node::create(['space_id' => $this->space->id, 'title' => 'Second']);
    Event::fake([SpaceUpdated::class]);

    $this->putJson("/api/spaces/{$this->space->id}/nodes/bulk-move", [
        'positions' => [
            ['id' => $this->root->id, 'pos_x' => 1, 'pos_y' => 2],
            ['id' => $second->id, 'pos_x' => 3, 'pos_y' => 4],
        ],
    ])->assertStatus(200);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);
});

test('linking and unlinking nodes signals the space', function () {
    $child = Node::create(['space_id' => $this->space->id, 'title' => 'Child', 'depth' => 1]);
    Event::fake([SpaceUpdated::class]);

    $this->postJson("/api/spaces/{$this->space->id}/links", [
        'parent_id' => $this->root->id,
        'child_id' => $child->id,
    ])->assertStatus(201);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);

    Event::fake([SpaceUpdated::class]);

    $this->deleteJson("/api/spaces/{$this->space->id}/links", [
        'parent_id' => $this->root->id,
        'child_id' => $child->id,
    ])->assertStatus(200);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);
});

test('deleting and restoring nodes signals the space', function () {
    Event::fake([SpaceUpdated::class]);

    $deleted = $this->postJson("/api/spaces/{$this->space->id}/nodes/delete-many", [
        'ids' => [$this->root->id],
    ])->assertStatus(200)->json();

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);

    Event::fake([SpaceUpdated::class]);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/restore", [
        'undo_token' => $deleted['undo_token'],
    ])->assertStatus(200);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);
});

test('uploading and deleting an attachment signals the space', function () {
    Event::fake([SpaceUpdated::class]);

    $attachment = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->root->id}/attachments",
        ['kind' => 'link', 'label' => 'Docs', 'url' => 'https://example.com'],
    )->assertStatus(201)->json();

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);

    Event::fake([SpaceUpdated::class]);

    $this->delete("/api/spaces/{$this->space->id}/nodes/{$this->root->id}/attachments/{$attachment['id']}")
        ->assertStatus(200);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);
});

test('switching space structure signals the space', function () {
    Event::fake([SpaceUpdated::class]);

    $this->putJson("/api/spaces/{$this->space->id}/structure", ['structure' => 'network'])
        ->assertStatus(200);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $this->space->id);
});

test('deleting a user signals the admin space', function () {
    $admin = Space::where('is_admin', true)->firstOrFail();

    $created = $this->postJson('/api/admin/users', [
        'name' => 'Temp',
        'username' => 'temp-live',
        'email' => 'temp-live@example.com',
        'password' => 'password1234',
    ])->json();

    Event::fake([SpaceUpdated::class]);

    $this->deleteJson("/api/admin/users/{$created['user']['id']}")->assertStatus(200);

    Event::assertDispatched(SpaceUpdated::class, fn (SpaceUpdated $e) => $e->spaceId === $admin->id);
});
