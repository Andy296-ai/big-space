<?php

use App\Models\Node;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->editor = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->actingAs($this->owner);
    $this->space = Space::create(['name' => 'Feed', 'slug' => 'feed-space', 'user_id' => $this->owner->id]);
    $this->space->collaborators()->attach($this->editor->id, ['role' => 'editor']);
});

test('creating and deleting nodes leaves a trail scoped to the space', function () {
    $root = $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'Root'])->json();
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$root['id']}/child", ['title' => 'Child']);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/delete-many", ['ids' => [$root['id']]]);

    $entries = $this->getJson("/api/spaces/{$this->space->id}/activity-log")
        ->assertStatus(200)
        ->json('entries');

    $actions = collect($entries)->pluck('action')->all();
    expect($actions)->toBe(['node.deleted', 'node.created', 'node.created']);
    expect($entries[0]['meta']['titles'])->toBe(['Root']);
    expect($entries[0]['actor']['id'])->toBe($this->owner->id);
});

test('linking and unlinking nodes, and switching structure mode, are logged as structure changes', function () {
    $a = Node::create(['space_id' => $this->space->id, 'title' => 'A', 'depth' => 0]);
    $b = Node::create(['space_id' => $this->space->id, 'title' => 'B', 'depth' => 0]);

    $this->postJson("/api/spaces/{$this->space->id}/links", ['parent_id' => $a->id, 'child_id' => $b->id])
        ->assertStatus(201);
    $this->deleteJson("/api/spaces/{$this->space->id}/links", ['parent_id' => $a->id, 'child_id' => $b->id])
        ->assertStatus(200);
    $this->putJson("/api/spaces/{$this->space->id}/structure", ['structure' => 'network'])
        ->assertStatus(200);

    $entries = $this->getJson("/api/spaces/{$this->space->id}/activity-log")->json('entries');
    $actions = collect($entries)->pluck('action')->all();

    expect($actions)->toBe(['structure.changed', 'structure.changed', 'structure.changed']);
    expect($entries[0]['meta']['action'])->toBe('mode_changed');
    expect($entries[1]['meta']['action'])->toBe('unlinked');
    expect($entries[1]['meta']['parent_title'])->toBe('A');
    expect($entries[1]['meta']['child_title'])->toBe('B');
    expect($entries[2]['meta']['action'])->toBe('linked');
});

test('sharing changes are logged: added, role changed, removed', function () {
    $friend = User::factory()->create(['name' => 'shareable-friend']);

    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'shareable-friend',
        'role' => 'viewer',
    ])->assertStatus(201);

    $this->putJson("/api/spaces/{$this->space->id}/collaborators/{$friend->id}", ['role' => 'editor'])
        ->assertStatus(200);

    $this->deleteJson("/api/spaces/{$this->space->id}/collaborators/{$friend->id}")->assertStatus(200);

    $entries = $this->getJson("/api/spaces/{$this->space->id}/activity-log")->json('entries');
    $actions = collect($entries)->pluck('action')->all();

    expect($actions)->toBe([
        'collaborator.removed',
        'collaborator.role_changed',
        'collaborator.added',
    ]);
    expect($entries[0]['subject_id'])->toBe($friend->id);
});

test('an editor and a stranger cannot read the space activity log, only the owner and root', function () {
    $this->actingAs($this->editor);
    $this->getJson("/api/spaces/{$this->space->id}/activity-log")->assertStatus(403);

    $this->actingAs($this->stranger);
    $this->getJson("/api/spaces/{$this->space->id}/activity-log")->assertStatus(403);

    $root = User::where('is_root', true)->firstOrFail();
    $this->actingAs($root);
    $this->getJson("/api/spaces/{$this->space->id}/activity-log")->assertStatus(200);
});

test('activity from a different space never leaks into this one\'s feed', function () {
    $otherSpace = Space::create(['name' => 'Other', 'slug' => 'other-space', 'user_id' => $this->owner->id]);
    $this->postJson("/api/spaces/{$otherSpace->id}/nodes/root", ['title' => 'Other root']);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'This root']);

    $entries = $this->getJson("/api/spaces/{$this->space->id}/activity-log")->json('entries');

    expect($entries)->toHaveCount(1);
    expect($entries[0]['meta']['title'])->toBe('This root');
});
