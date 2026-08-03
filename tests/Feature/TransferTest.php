<?php

use App\Models\Edge;
use App\Models\Node;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
});

function sampleSpace(string $slug, string $structure = Space::STRUCTURE_DAG): Space
{
    $space = Space::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'structure' => $structure,
    ]);

    $root = Node::create(['space_id' => $space->id, 'title' => 'Цель', 'depth' => 0]);
    $a = Node::create(['space_id' => $space->id, 'title' => 'Стратегия', 'depth' => 1]);
    $b = Node::create(['space_id' => $space->id, 'title' => 'Тактика', 'depth' => 2]);

    Edge::create(['space_id' => $space->id, 'parent_id' => $root->id, 'child_id' => $a->id]);
    Edge::create(['space_id' => $space->id, 'parent_id' => $a->id, 'child_id' => $b->id]);

    return $space;
}

test('export returns the whole space keyed by file-local numbers', function () {
    $space = sampleSpace('export-me', Space::STRUCTURE_LEVELED);

    $response = $this->getJson("/api/spaces/{$space->id}/export");
    $response->assertStatus(200);

    expect($response->json('format'))->toBe('infinite-space/v1');
    expect($response->json('space.structure'))->toBe(Space::STRUCTURE_LEVELED);
    expect($response->json('nodes'))->toHaveCount(3);
    expect($response->json('edges'))->toHaveCount(2);

    // Ключи — порядковые номера внутри файла, а не id из базы.
    expect(array_column($response->json('nodes'), 'key'))->toBe([1, 2, 3]);
    expect($response->json('edges.0'))->toBe(['parent' => 1, 'child' => 2]);
});

test('a round trip rebuilds the same graph in a new space', function () {
    $original = sampleSpace('round-trip');
    $payload = $this->getJson("/api/spaces/{$original->id}/export")->json();

    $imported = $this->postJson('/api/spaces/import', $payload);
    $imported->assertStatus(201);

    $newId = $imported->json('id');
    expect($newId)->not->toBe($original->id);
    expect($imported->json('nodes_count'))->toBe(3);
    expect($imported->json('edges_count'))->toBe(2);

    // Слаг разведён, исходное пространство не тронуто.
    expect($imported->json('slug'))->not->toBe($original->slug);
    expect(Node::where('space_id', $original->id)->count())->toBe(3);

    $again = $this->getJson("/api/spaces/{$newId}/export")->json();
    expect($again['edges'])->toBe($payload['edges']);
    expect(array_column($again['nodes'], 'title'))
        ->toBe(array_column($payload['nodes'], 'title'));
});

test('import recomputes depth instead of trusting the file', function () {
    $payload = [
        'format' => 'infinite-space/v1',
        'space' => ['name' => 'Depths', 'structure' => Space::STRUCTURE_DAG],
        'nodes' => [
            ['key' => 1, 'title' => 'Root', 'depth' => 99],
            ['key' => 2, 'title' => 'Child', 'depth' => 99],
        ],
        'edges' => [['parent' => 1, 'child' => 2]],
    ];

    $id = $this->postJson('/api/spaces/import', $payload)->assertStatus(201)->json('id');

    expect(Node::where('space_id', $id)->where('title', 'Root')->value('depth'))->toBe(0);
    expect(Node::where('space_id', $id)->where('title', 'Child')->value('depth'))->toBe(1);
});

test('import refuses a graph that breaks its declared structure', function () {
    $before = Space::count();

    // Два родителя у одного узла — для строгого дерева это недопустимо.
    $payload = [
        'format' => 'infinite-space/v1',
        'space' => ['name' => 'Bad Tree', 'structure' => Space::STRUCTURE_TREE],
        'nodes' => [
            ['key' => 1, 'title' => 'A'],
            ['key' => 2, 'title' => 'B'],
            ['key' => 3, 'title' => 'C'],
        ],
        'edges' => [
            ['parent' => 1, 'child' => 3],
            ['parent' => 2, 'child' => 3],
        ],
    ];

    $response = $this->postJson('/api/spaces/import', $payload);
    $response->assertStatus(422);
    expect($response->json('reason'))->toBe('single_parent');

    // Откат: ничего не осталось после неудачного импорта.
    expect(Space::count())->toBe($before);
    expect(Space::where('name', 'Bad Tree')->exists())->toBeFalse();
});

test('import rejects a foreign file and dangling edge keys', function () {
    $this->postJson('/api/spaces/import', ['format' => 'something/else', 'nodes' => []])
        ->assertStatus(422);

    $dangling = [
        'format' => 'infinite-space/v1',
        'space' => ['name' => 'Dangling'],
        'nodes' => [['key' => 1, 'title' => 'A']],
        'edges' => [['parent' => 1, 'child' => 42]],
    ];

    $response = $this->postJson('/api/spaces/import', $dangling);
    $response->assertStatus(422);
    expect($response->json('reason'))->toBe('unknown_edge_key');

    $duplicate = [
        'format' => 'infinite-space/v1',
        'space' => ['name' => 'Dup'],
        'nodes' => [['key' => 1, 'title' => 'A'], ['key' => 1, 'title' => 'B']],
        'edges' => [],
    ];

    expect($this->postJson('/api/spaces/import', $duplicate)->json('reason'))
        ->toBe('duplicate_keys');
});

test('guests cannot export or import', function () {
    $space = sampleSpace('guarded');

    auth()->logout();

    $this->getJson("/api/spaces/{$space->id}/export")->assertStatus(401);
    $this->postJson('/api/spaces/import', [])->assertStatus(401);
});
