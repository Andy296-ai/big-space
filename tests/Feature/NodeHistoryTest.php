<?php

use App\Models\Node;
use App\Models\NodeRevision;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
    $this->space = Space::create(['name' => 'History', 'slug' => 'history-space']);
    $this->node = Node::create([
        'space_id' => $this->space->id,
        'title' => 'Original title',
        'description' => 'Original description',
        'color' => '#111111',
    ]);
});

test('editing a node snapshots the previous state into its history', function () {
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}", [
        'title' => 'New title',
        'description' => 'New description',
        'color' => '#222222',
    ])->assertStatus(200);

    $revisions = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/revisions")
        ->assertStatus(200)
        ->json();

    expect($revisions)->toHaveCount(1);
    expect($revisions[0]['title'])->toBe('Original title');
    expect($revisions[0]['description'])->toBe('Original description');
    expect($revisions[0]['color'])->toBe('#111111');
    expect($revisions[0]['editor']['id'])->toBe(User::where('is_root', true)->value('id'));
});

test('history lists revisions newest first', function () {
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}", ['title' => 'Second']);
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}", ['title' => 'Third']);

    $revisions = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/revisions")->json();

    expect($revisions)->toHaveCount(2);
    expect($revisions[0]['title'])->toBe('Second'); // snapshot right before becoming "Third"
    expect($revisions[1]['title'])->toBe('Original title');
});

test('restoring a revision applies its values and is itself undoable', function () {
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}", ['title' => 'Changed title']);

    $revisions = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/revisions")->json();
    $revisionId = $revisions[0]['id'];

    $restored = $this->postJson(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/revisions/{$revisionId}/restore",
    )->assertStatus(200)->json();

    expect($restored['title'])->toBe('Original title');
    $this->assertDatabaseHas('nodes', ['id' => $this->node->id, 'title' => 'Original title']);

    // Restoring itself left a snapshot of "Changed title" — so restoring THAT undoes the restore.
    $afterRestoreRevisions = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/revisions")->json();
    expect($afterRestoreRevisions[0]['title'])->toBe('Changed title');
});

test('a revision belonging to a different node cannot be used to restore this one', function () {
    $otherNode = Node::create(['space_id' => $this->space->id, 'title' => 'Other']);
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$otherNode->id}", ['title' => 'Other changed']);
    $otherRevisionId = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$otherNode->id}/revisions")
        ->json()[0]['id'];

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/revisions/{$otherRevisionId}/restore")
        ->assertStatus(404);
});

test('a viewer can read history but cannot restore', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $space = Space::create(['name' => 'Owned', 'slug' => 'owned-history', 'user_id' => $owner->id]);
    $node = Node::create(['space_id' => $space->id, 'title' => 'V1']);
    $this->putJson("/api/spaces/{$space->id}/nodes/{$node->id}", ['title' => 'V2']);
    $revisionId = $this->getJson("/api/spaces/{$space->id}/nodes/{$node->id}/revisions")->json()[0]['id'];

    $viewer = User::factory()->create();
    $space->collaborators()->attach($viewer->id, ['role' => 'viewer']);
    $this->actingAs($viewer);

    $this->getJson("/api/spaces/{$space->id}/nodes/{$node->id}/revisions")->assertStatus(200);
    $this->postJson("/api/spaces/{$space->id}/nodes/{$node->id}/revisions/{$revisionId}/restore")
        ->assertStatus(403);
});

test('history is capped at the configured maximum per node', function () {
    for ($i = 0; $i < NodeRevision::MAX_PER_NODE + 2; $i++) {
        $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}", ['title' => "Title {$i}"]);
    }

    $this->assertDatabaseCount('node_revisions', NodeRevision::MAX_PER_NODE);

    $revisions = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/revisions")->json();
    // The oldest surviving snapshot should not be the very first edit anymore — it got pruned.
    expect(collect($revisions)->pluck('title'))->not->toContain('Original title');
});
