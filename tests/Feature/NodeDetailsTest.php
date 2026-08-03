<?php

use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
});

test('graph payload carries map fields and attachments', function () {
    $space = Space::create(['name' => 'Details', 'slug' => 'details']);

    $withExtras = Node::create([
        'space_id' => $space->id,
        'title' => 'С картой',
        'map_lat' => 38.5598,
        'map_lon' => 68.787,
        'map_title' => 'Душанбе',
    ]);
    $withExtras->attachments()->create([
        'kind' => NodeAttachment::KIND_FILE,
        'label' => 'Смета',
        'url' => 'https://example.com/files/budget.pdf',
        'format' => 'PDF',
    ]);

    $bare = Node::create(['space_id' => $space->id, 'title' => 'Без всего']);

    $payload = $this->getJson("/api/spaces/{$space->id}/graph")->assertStatus(200)->json();

    $rich = collect($payload['nodes'])->firstWhere('id', $withExtras->id);
    expect($rich['map_lat'])->toBe(38.5598);
    expect($rich['map_title'])->toBe('Душанбе');
    expect($rich['attachments'])->toHaveCount(1);
    expect($rich['attachments'][0]['badge'])->toBe('PDF');

    // Пустой узел не должен получать ни карты, ни вложений — панель их скроет.
    $plain = collect($payload['nodes'])->firstWhere('id', $bare->id);
    expect($plain['map_lat'])->toBeNull();
    expect($plain['attachments'])->toBe([]);
});

test('badge falls back to the extension in the url', function () {
    $space = Space::create(['name' => 'Badge', 'slug' => 'badge']);
    $node = Node::create(['space_id' => $space->id, 'title' => 'N']);

    $node->attachments()->create([
        'kind' => NodeAttachment::KIND_FILE,
        'url' => 'https://example.com/files/archive.zip?v=2',
    ]);

    expect($node->attachments()->first()->badge)->toBe('ZIP');
});

test('deleting a node removes its attachments', function () {
    $space = Space::create(['name' => 'Cascade', 'slug' => 'cascade-att']);
    $node = Node::create(['space_id' => $space->id, 'title' => 'N']);
    $node->attachments()->create([
        'url' => 'https://example.com/a.pdf',
        'kind' => NodeAttachment::KIND_FILE,
    ]);

    $this->postJson("/api/spaces/{$space->id}/nodes/delete-many", ['ids' => [$node->id]])
        ->assertStatus(200);

    expect(NodeAttachment::where('node_id', $node->id)->count())->toBe(0);
});

test('export and import keep the map and attachments', function () {
    $space = Space::create(['name' => 'Transfer extras', 'slug' => 'transfer-extras']);
    $node = Node::create([
        'space_id' => $space->id,
        'title' => 'Точка',
        'map_lat' => 38.5,
        'map_lon' => 68.7,
        'map_title' => 'Метка',
    ]);
    $node->attachments()->create([
        'kind' => NodeAttachment::KIND_LINK,
        'label' => 'Сайт',
        'url' => 'https://example.com',
        'format' => 'COM',
    ]);

    $payload = $this->getJson("/api/spaces/{$space->id}/export")->json();
    expect($payload['nodes'][0]['map_title'])->toBe('Метка');
    expect($payload['nodes'][0]['attachments'])->toHaveCount(1);

    $newId = $this->postJson('/api/spaces/import', $payload)->assertStatus(201)->json('id');
    $copy = Node::where('space_id', $newId)->with('attachments')->first();

    expect($copy->map_title)->toBe('Метка');
    expect($copy->attachments)->toHaveCount(1);
    expect($copy->attachments[0]->kind)->toBe(NodeAttachment::KIND_LINK);
});

test('node update saves and clears the map point', function () {
    $space = Space::create(['name' => 'Map edit', 'slug' => 'map-edit']);
    $node = Node::create(['space_id' => $space->id, 'title' => 'N']);

    $this->putJson("/api/spaces/{$space->id}/nodes/{$node->id}", [
        'title' => 'N',
        'map_lat' => 38.5,
        'map_lon' => 68.7,
        'map_title' => 'Здесь',
    ])->assertStatus(200);

    expect($node->fresh()->map_title)->toBe('Здесь');

    // Без полей карты точка снимается.
    $this->putJson("/api/spaces/{$space->id}/nodes/{$node->id}", ['title' => 'N'])
        ->assertStatus(200);

    expect($node->fresh()->map_lat)->toBeNull();
});

test('map coordinates are validated', function () {
    $space = Space::create(['name' => 'Map valid', 'slug' => 'map-valid']);
    $node = Node::create(['space_id' => $space->id, 'title' => 'N']);

    $this->putJson("/api/spaces/{$space->id}/nodes/{$node->id}", [
        'title' => 'N',
        'map_lat' => 999,
        'map_lon' => 68.7,
    ])->assertStatus(422);
});
