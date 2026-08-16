<?php

use App\Models\Edge;
use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Share;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->space = Space::create(['name' => 'Shared', 'slug' => 'shared-space', 'user_id' => $this->owner->id]);
    $this->rootA = Node::create(['space_id' => $this->space->id, 'title' => 'Root A']);
    $this->rootB = Node::create(['space_id' => $this->space->id, 'title' => 'Root B']);
});

test('a non-owner editor cannot manage share links', function () {
    $editor = User::factory()->create();
    $this->space->collaborators()->attach($editor->id, ['role' => 'editor']);

    $this->actingAs($editor);
    $this->postJson("/api/spaces/{$this->space->id}/share")->assertStatus(403);
});

test('generating a whole-space link and fetching its public graph works with no auth at all', function () {
    $this->actingAs($this->owner);
    $created = $this->postJson("/api/spaces/{$this->space->id}/share")->assertStatus(201)->json();

    // Разлогиниваемся полностью — публичный маршрут не должен требовать сессии вообще.
    auth()->logout();

    $graph = $this->getJson("/api/shared/{$created['token']}/graph")->assertStatus(200)->json();
    $ids = collect($graph['nodes'])->pluck('id');
    expect($ids)->toContain($this->rootA->id, $this->rootB->id);
});

test('regenerating a whole-space link invalidates the previous token', function () {
    $this->actingAs($this->owner);
    $first = $this->postJson("/api/spaces/{$this->space->id}/share")->json();
    $second = $this->postJson("/api/spaces/{$this->space->id}/share")->json();

    expect($second['token'])->not->toBe($first['token']);
    $this->getJson("/api/shared/{$first['token']}/graph")->assertStatus(404);
    $this->getJson("/api/shared/{$second['token']}/graph")->assertStatus(200);
});

test('revoking a share makes the public link 404', function () {
    $this->actingAs($this->owner);
    $created = $this->postJson("/api/spaces/{$this->space->id}/share")->json();

    $this->deleteJson("/api/spaces/{$this->space->id}/share")->assertStatus(200);

    $this->getJson("/api/shared/{$created['token']}/graph")->assertStatus(404);
});

test(
    'a subtree share correctly includes a node reachable through the shared root even though it has a second parent outside the scope',
    function () {
        // dag: X имеет ДВА родителя — rootA (внутри шеринга) и rootB (снаружи).
        // computeDeletionSetForSpace() ответил бы на другой вопрос ("что
        // недостижимо от ВСЕХ остальных корней") и решил бы, что X не входит
        // в удаляемое поддерево rootA, потому что rootB всё ещё до него
        // дотягивается — но для шеринга вопрос другой: "это потомок rootA?"
        // Да, потомок, и он обязан попасть в шаренное поддерево. Именно
        // поэтому collectSubtreeIds() — отдельный метод с чистым forward BFS,
        // а не переиспользование deletion-set логики.
        $this->space->update(['structure' => Space::STRUCTURE_DAG]);
        $x = Node::create(['space_id' => $this->space->id, 'title' => 'Shared child X']);
        $y = Node::create(['space_id' => $this->space->id, 'title' => 'Grandchild Y']);
        Edge::create(['space_id' => $this->space->id, 'parent_id' => $this->rootA->id, 'child_id' => $x->id]);
        Edge::create(['space_id' => $this->space->id, 'parent_id' => $this->rootB->id, 'child_id' => $x->id]);
        Edge::create(['space_id' => $this->space->id, 'parent_id' => $x->id, 'child_id' => $y->id]);

        $this->actingAs($this->owner);
        $created = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->rootA->id}/share")
            ->assertStatus(201)
            ->json();

        $graph = $this->getJson("/api/shared/{$created['token']}/graph")->assertStatus(200)->json();
        $ids = collect($graph['nodes'])->pluck('id');

        expect($ids)->toContain($this->rootA->id, $x->id, $y->id);
        expect($ids)->not->toContain($this->rootB->id);
    },
);

test('a subtree share and a whole-space share can coexist independently', function () {
    $this->actingAs($this->owner);
    $whole = $this->postJson("/api/spaces/{$this->space->id}/share")->assertStatus(201)->json();
    $subtree = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->rootA->id}/share")
        ->assertStatus(201)
        ->json();

    expect($whole['token'])->not->toBe($subtree['token']);

    $subtreeGraph = $this->getJson("/api/shared/{$subtree['token']}/graph")->json();
    $ids = collect($subtreeGraph['nodes'])->pluck('id');
    expect($ids)->toContain($this->rootA->id);
    expect($ids)->not->toContain($this->rootB->id);
});

test('a public attachment download 404s for a node outside the shared subtree', function () {
    $node = Node::create(['space_id' => $this->space->id, 'title' => 'Unshared sibling']);
    $attachment = NodeAttachment::create([
        'node_id' => $node->id,
        'kind' => NodeAttachment::KIND_FILE,
        'label' => 'secret.txt',
        'path' => 'nodes/'.$node->id.'/secret.txt',
        'size' => 4,
        'format' => 'txt',
    ]);
    Storage::disk(NodeAttachment::DISK)->put($attachment->path, 'data');

    $this->actingAs($this->owner);
    $created = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->rootA->id}/share")->json();

    $this->getJson("/api/shared/{$created['token']}/nodes/{$node->id}/attachments/{$attachment->id}/download")
        ->assertStatus(404);
});

test('a public attachment download succeeds for a node inside the shared subtree', function () {
    $attachment = NodeAttachment::create([
        'node_id' => $this->rootA->id,
        'kind' => NodeAttachment::KIND_FILE,
        'label' => 'notes.txt',
        'path' => 'nodes/'.$this->rootA->id.'/notes.txt',
        'size' => 4,
        'format' => 'txt',
    ]);
    Storage::disk(NodeAttachment::DISK)->put($attachment->path, 'data');

    $this->actingAs($this->owner);
    $created = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->rootA->id}/share")->json();

    $this->getJson("/api/shared/{$created['token']}/nodes/{$this->rootA->id}/attachments/{$attachment->id}/download")
        ->assertStatus(200);
});

test('deleting the space cascades to delete its share links', function () {
    $this->actingAs($this->owner);
    $created = $this->postJson("/api/spaces/{$this->space->id}/share")->json();

    $this->space->delete();

    $this->assertDatabaseMissing('shares', ['token' => $created['token']]);
});

test('the public share page renders via Inertia without requiring authentication', function () {
    $share = Share::create([
        'space_id' => $this->space->id,
        'node_id' => null,
        'token' => 'a-public-token-1234567890',
        'created_by' => $this->owner->id,
        'created_at' => now(),
    ]);

    $response = $this->get("/shared/{$share->token}");
    $response->assertStatus(200);
});
