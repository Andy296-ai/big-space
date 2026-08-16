<?php

use App\Models\Edge;
use App\Models\Node;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Прямая запись embedding'а через сырой SQL — тот же путь, что EmbeddingService::store(), без реального похода к Ollama. */
function setNodeEmbedding(int $nodeId, array $vector): void
{
    $literal = '['.implode(',', $vector).']';
    DB::statement('UPDATE nodes SET embedding = ?::vector WHERE id = ?', [$literal, $nodeId]);
}

function oneHotVector(int $index): array
{
    $vector = array_fill(0, 768, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->space = Space::create(['name' => 'Suggestion Space', 'slug' => 'suggestion-space', 'user_id' => $this->owner->id]);
    $this->actingAs($this->owner);
});

test('suggested links only include nodes close by meaning, not far ones', function () {
    $a = Node::create(['space_id' => $this->space->id, 'title' => 'A']);
    $close = Node::create(['space_id' => $this->space->id, 'title' => 'Close']);
    $far = Node::create(['space_id' => $this->space->id, 'title' => 'Far']);

    setNodeEmbedding($a->id, oneHotVector(0));
    setNodeEmbedding($close->id, oneHotVector(0));
    setNodeEmbedding($far->id, oneHotVector(1));

    $response = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$a->id}/suggested-links")
        ->assertStatus(200)
        ->json();

    $ids = collect($response)->pluck('id');
    expect($ids)->toContain($close->id);
    expect($ids)->not->toContain($far->id);
});

test('a node already linked to the source is never suggested', function () {
    $a = Node::create(['space_id' => $this->space->id, 'title' => 'A']);
    $linked = Node::create(['space_id' => $this->space->id, 'title' => 'Linked']);

    setNodeEmbedding($a->id, oneHotVector(0));
    setNodeEmbedding($linked->id, oneHotVector(0));
    Edge::create(['space_id' => $this->space->id, 'parent_id' => $a->id, 'child_id' => $linked->id]);

    $response = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$a->id}/suggested-links")->json();

    expect(collect($response)->pluck('id'))->not->toContain($linked->id);
});

test('dismissing a suggestion hides it from future suggestion lists', function () {
    $a = Node::create(['space_id' => $this->space->id, 'title' => 'A']);
    $b = Node::create(['space_id' => $this->space->id, 'title' => 'B']);

    setNodeEmbedding($a->id, oneHotVector(0));
    setNodeEmbedding($b->id, oneHotVector(0));

    $before = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$a->id}/suggested-links")->json();
    expect(collect($before)->pluck('id'))->toContain($b->id);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$a->id}/suggested-links/{$b->id}/dismiss")
        ->assertStatus(200);

    $after = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$a->id}/suggested-links")->json();
    expect(collect($after)->pluck('id'))->not->toContain($b->id);

    // Симметрично — тот же результат, если запрос идёт со стороны B.
    $fromOtherSide = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$b->id}/suggested-links")->json();
    expect(collect($fromOtherSide)->pluck('id'))->not->toContain($a->id);
});

test(
    'accepting a suggestion that would violate tree structure (a second parent) is rejected by link(), the real validation gate',
    function () {
        $this->space->update(['structure' => Space::STRUCTURE_TREE]);

        $root = Node::create(['space_id' => $this->space->id, 'title' => 'Root']);
        $existingParent = Node::create(['space_id' => $this->space->id, 'title' => 'Existing parent']);
        $child = Node::create(['space_id' => $this->space->id, 'title' => 'Already has a parent']);
        Edge::create(['space_id' => $this->space->id, 'parent_id' => $existingParent->id, 'child_id' => $child->id]);

        // Подсказка сама по себе не обязана знать о структурных правилах —
        // достаточно, что финальный accept идёт через настоящий link().
        $response = $this->postJson("/api/spaces/{$this->space->id}/links", [
            'parent_id' => $root->id,
            'child_id' => $child->id,
        ]);

        $response->assertStatus(422);
        expect($response->json('reason'))->toBe('single_parent');
        expect(Edge::where('child_id', $child->id)->count())->toBe(1);
    },
);

test('a user without edit access cannot view or dismiss suggestions', function () {
    $viewer = User::factory()->create();
    $this->space->collaborators()->attach($viewer->id, ['role' => 'viewer']);

    $a = Node::create(['space_id' => $this->space->id, 'title' => 'A']);
    $b = Node::create(['space_id' => $this->space->id, 'title' => 'B']);

    $this->actingAs($viewer);

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$a->id}/suggested-links")->assertStatus(403);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$a->id}/suggested-links/{$b->id}/dismiss")->assertStatus(403);
});
