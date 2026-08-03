<?php

use App\Models\Edge;
use App\Models\Node;
use App\Models\Space;
use App\Models\User;
use App\Services\GraphRepository;
use App\Services\LayoutEngine;

// Всё приложение за авторизацией — графовые тесты работают от учётки root,
// которую создаёт миграция.
beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
});

test('creates a space and seeds root origin node', function () {
    $response = $this->get('/');
    $response->assertStatus(200);

    $this->assertDatabaseHas('spaces', ['slug' => 'default-space']);
    $this->assertDatabaseHas('nodes', ['title' => 'Origin', 'depth' => 0]);
});

test('calculates golden angle placement for child nodes', function () {
    $space = Space::create(['name' => 'Test Space', 'slug' => 'test-space']);
    $parent = Node::create([
        'space_id' => $space->id,
        'title' => 'Root',
        'pos_x' => 0,
        'pos_y' => 0,
        'pos_z' => 0,
        'depth' => 0,
    ]);

    $pos0 = LayoutEngine::placeChild($parent, 0);
    $pos1 = LayoutEngine::placeChild($parent, 1);

    expect($pos0['x'])->not->toBe($pos1['x']);
    expect($pos0['z'])->toBe(0.0);
});

test('detects and prevents cyclic node links', function () {
    $repo = new GraphRepository;
    $space = Space::create(['name' => 'Cycle Space', 'slug' => 'cycle-space']);

    $nodeA = Node::create(['space_id' => $space->id, 'title' => 'A']);
    $nodeB = Node::create(['space_id' => $space->id, 'title' => 'B']);
    $nodeC = Node::create(['space_id' => $space->id, 'title' => 'C']);

    // A -> B -> C
    Edge::create(['space_id' => $space->id, 'parent_id' => $nodeA->id, 'child_id' => $nodeB->id]);
    Edge::create(['space_id' => $space->id, 'parent_id' => $nodeB->id, 'child_id' => $nodeC->id]);

    // Adding C -> A would create a cycle
    $wouldCycle = $repo->wouldCreateCycle($space->id, $nodeC->id, $nodeA->id);
    expect($wouldCycle)->toBeTrue();

    // Adding A -> C should not cycle (already reachable downstream)
    $wouldCycle2 = $repo->wouldCreateCycle($space->id, $nodeA->id, $nodeC->id);
    expect($wouldCycle2)->toBeFalse();
});

test('a strict tree refuses a second parent but a dag accepts it', function () {
    $space = Space::create([
        'name' => 'Tree Space',
        'slug' => 'tree-space',
        'structure' => Space::STRUCTURE_TREE,
    ]);

    $parentA = Node::create(['space_id' => $space->id, 'title' => 'A']);
    $parentB = Node::create(['space_id' => $space->id, 'title' => 'B']);
    $child = Node::create(['space_id' => $space->id, 'title' => 'C']);

    Edge::create(['space_id' => $space->id, 'parent_id' => $parentA->id, 'child_id' => $child->id]);

    $rejected = $this->postJson("/api/spaces/{$space->id}/links", [
        'parent_id' => $parentB->id,
        'child_id' => $child->id,
    ]);
    $rejected->assertStatus(422);
    expect($rejected->json('reason'))->toBe('single_parent');

    // То же самое ребро в DAG проходит.
    $space->update(['structure' => Space::STRUCTURE_DAG]);

    $accepted = $this->postJson("/api/spaces/{$space->id}/links", [
        'parent_id' => $parentB->id,
        'child_id' => $child->id,
    ]);
    $accepted->assertStatus(201);
});

test('a network accepts cycles that a dag rejects', function () {
    $space = Space::create([
        'name' => 'Net Space',
        'slug' => 'net-space',
        'structure' => Space::STRUCTURE_DAG,
    ]);

    $a = Node::create(['space_id' => $space->id, 'title' => 'A']);
    $b = Node::create(['space_id' => $space->id, 'title' => 'B']);
    Edge::create(['space_id' => $space->id, 'parent_id' => $a->id, 'child_id' => $b->id]);

    $rejected = $this->postJson("/api/spaces/{$space->id}/links", [
        'parent_id' => $b->id,
        'child_id' => $a->id,
    ]);
    $rejected->assertStatus(422);
    expect($rejected->json('reason'))->toBe('cycle');

    $space->update(['structure' => Space::STRUCTURE_NETWORK]);

    $accepted = $this->postJson("/api/spaces/{$space->id}/links", [
        'parent_id' => $b->id,
        'child_id' => $a->id,
    ]);
    $accepted->assertStatus(201);

    expect((new GraphRepository)->hasCycle($space->id))->toBeTrue();
});

test('switching structure is refused when the existing graph violates it', function () {
    $space = Space::create([
        'name' => 'Switch Space',
        'slug' => 'switch-space',
        'structure' => Space::STRUCTURE_DAG,
    ]);

    $parentA = Node::create(['space_id' => $space->id, 'title' => 'A']);
    $parentB = Node::create(['space_id' => $space->id, 'title' => 'B']);
    $child = Node::create(['space_id' => $space->id, 'title' => 'C']);

    Edge::create(['space_id' => $space->id, 'parent_id' => $parentA->id, 'child_id' => $child->id]);
    Edge::create(['space_id' => $space->id, 'parent_id' => $parentB->id, 'child_id' => $child->id]);

    $refused = $this->putJson("/api/spaces/{$space->id}/structure", [
        'structure' => Space::STRUCTURE_TREE,
    ]);
    $refused->assertStatus(422);
    expect($refused->json('reason'))->toBe('single_parent');
    expect($refused->json('conflicts'))->toBe(1);
    expect($space->fresh()->structure)->toBe(Space::STRUCTURE_DAG);

    // Сеть принимает любой существующий граф.
    $this->putJson("/api/spaces/{$space->id}/structure", [
        'structure' => Space::STRUCTURE_NETWORK,
    ])->assertStatus(200);

    expect($space->fresh()->structure)->toBe(Space::STRUCTURE_NETWORK);
});

test('deletion does not cascade in a network', function () {
    $repo = new GraphRepository;
    $space = Space::create([
        'name' => 'Net Delete',
        'slug' => 'net-delete',
        'structure' => Space::STRUCTURE_NETWORK,
    ]);

    $root = Node::create(['space_id' => $space->id, 'title' => 'Root']);
    $child = Node::create(['space_id' => $space->id, 'title' => 'Child']);
    Edge::create(['space_id' => $space->id, 'parent_id' => $root->id, 'child_id' => $child->id]);

    // В DAG удаление корня утащило бы и потомка, в сети — нет.
    expect($repo->computeDeletionSetForSpace($space, [$root->id]))->toBe([$root->id]);

    $space->update(['structure' => Space::STRUCTURE_DAG]);
    expect($repo->computeDeletionSetForSpace($space->fresh(), [$root->id]))
        ->toContain($root->id, $child->id);
});

test('computes subtree deletion set and allows restore undo', function () {
    $repo = new GraphRepository;
    $space = Space::create(['name' => 'Delete Space', 'slug' => 'delete-space']);

    $root = Node::create(['space_id' => $space->id, 'title' => 'Root']);
    $child = Node::create(['space_id' => $space->id, 'title' => 'Child']);
    $grandchild = Node::create(['space_id' => $space->id, 'title' => 'Grandchild']);

    Edge::create(['space_id' => $space->id, 'parent_id' => $root->id, 'child_id' => $child->id]);
    Edge::create(['space_id' => $space->id, 'parent_id' => $child->id, 'child_id' => $grandchild->id]);

    // Deleting root should mark root, child, and grandchild for deletion
    $deletionSet = $repo->computeDeletionSet($space->id, $root->id);
    expect($deletionSet)->toContain($root->id, $child->id, $grandchild->id);

    // Delete nodes via API
    $deleteRes = $this->postJson("/api/spaces/{$space->id}/nodes/delete-many", [
        'ids' => $deletionSet,
    ]);
    $deleteRes->assertStatus(200);

    $this->assertDatabaseMissing('nodes', ['id' => $root->id]);
    $this->assertDatabaseMissing('nodes', ['id' => $child->id]);

    // Restore via Undo API
    $restoreRes = $this->postJson("/api/spaces/{$space->id}/nodes/restore", [
        'undo_token' => $deleteRes->json('undo_token'),
    ]);
    $restoreRes->assertStatus(200);

    $this->assertDatabaseHas('nodes', ['id' => $root->id]);
    $this->assertDatabaseHas('nodes', ['id' => $child->id]);
});

test('deletion recomputes the cascade instead of trusting the client', function () {
    $space = Space::create(['name' => 'Trust Space', 'slug' => 'trust-space']);

    $root = Node::create(['space_id' => $space->id, 'title' => 'Root']);
    $child = Node::create(['space_id' => $space->id, 'title' => 'Child']);
    Edge::create(['space_id' => $space->id, 'parent_id' => $root->id, 'child_id' => $child->id]);

    // Клиент просит удалить только корень — потомок всё равно должен уйти,
    // потому что каскад считает сервер.
    $this->postJson("/api/spaces/{$space->id}/nodes/delete-many", ['ids' => [$root->id]])
        ->assertStatus(200);

    $this->assertDatabaseMissing('nodes', ['id' => $root->id]);
    $this->assertDatabaseMissing('nodes', ['id' => $child->id]);
});

test('deletion ignores node ids belonging to another space', function () {
    $mine = Space::create(['name' => 'Mine', 'slug' => 'mine']);
    $theirs = Space::create(['name' => 'Theirs', 'slug' => 'theirs']);

    $myNode = Node::create(['space_id' => $mine->id, 'title' => 'Mine']);
    $foreignNode = Node::create(['space_id' => $theirs->id, 'title' => 'Theirs']);

    $this->postJson("/api/spaces/{$mine->id}/nodes/delete-many", [
        'ids' => [$myNode->id, $foreignNode->id],
    ])->assertStatus(200);

    $this->assertDatabaseMissing('nodes', ['id' => $myNode->id]);
    $this->assertDatabaseHas('nodes', ['id' => $foreignNode->id]);
});

test('restore refuses an unknown or foreign undo token', function () {
    $space = Space::create(['name' => 'Undo Space', 'slug' => 'undo-space']);
    $other = Space::create(['name' => 'Other Space', 'slug' => 'other-space']);
    $node = Node::create(['space_id' => $space->id, 'title' => 'Doomed']);

    $this->postJson("/api/spaces/{$space->id}/nodes/restore", ['undo_token' => 'made-up'])
        ->assertStatus(410);

    $token = $this->postJson("/api/spaces/{$space->id}/nodes/delete-many", ['ids' => [$node->id]])
        ->json('undo_token');

    // Токен привязан к своему пространству и в чужом не работает.
    $this->postJson("/api/spaces/{$other->id}/nodes/restore", ['undo_token' => $token])
        ->assertStatus(410);
    $this->assertDatabaseMissing('nodes', ['id' => $node->id]);

    // А в своём — работает, и ровно один раз.
    $this->postJson("/api/spaces/{$space->id}/nodes/restore", ['undo_token' => $token])
        ->assertStatus(200);
    $this->assertDatabaseHas('nodes', ['id' => $node->id]);

    $this->postJson("/api/spaces/{$space->id}/nodes/restore", ['undo_token' => $token])
        ->assertStatus(410);
});

test('a leveled space keeps every link exactly one level apart', function () {
    $space = Space::create([
        'name' => 'Leveled Space',
        'slug' => 'leveled-space',
        'structure' => Space::STRUCTURE_LEVELED,
    ]);

    $root = Node::create(['space_id' => $space->id, 'title' => 'Root', 'depth' => 0]);
    $mid = Node::create(['space_id' => $space->id, 'title' => 'Mid', 'depth' => 1]);
    $leaf = Node::create(['space_id' => $space->id, 'title' => 'Leaf', 'depth' => 2]);

    Edge::create(['space_id' => $space->id, 'parent_id' => $root->id, 'child_id' => $mid->id]);
    Edge::create(['space_id' => $space->id, 'parent_id' => $mid->id, 'child_id' => $leaf->id]);

    // Корень напрямую к листу — перепрыгивает уровень, и сдвинуть лист нельзя:
    // у него уже есть родитель на глубине 1.
    $rejected = $this->postJson("/api/spaces/{$space->id}/links", [
        'parent_id' => $root->id,
        'child_id' => $leaf->id,
    ]);
    $rejected->assertStatus(422);
    expect($rejected->json('reason'))->toBe('level_gap');

    // В DAG то же самое ребро законно.
    $space->update(['structure' => Space::STRUCTURE_DAG]);
    $this->postJson("/api/spaces/{$space->id}/links", [
        'parent_id' => $root->id,
        'child_id' => $leaf->id,
    ])->assertStatus(201);
});

test('linking in a leveled space shifts the child subtree into place', function () {
    $space = Space::create([
        'name' => 'Shift Space',
        'slug' => 'shift-space',
        'structure' => Space::STRUCTURE_LEVELED,
    ]);

    $root = Node::create(['space_id' => $space->id, 'title' => 'Root', 'depth' => 0]);
    $child = Node::create(['space_id' => $space->id, 'title' => 'Child', 'depth' => 1]);
    Edge::create(['space_id' => $space->id, 'parent_id' => $root->id, 'child_id' => $child->id]);

    // Отдельное деревце на нулевом уровне.
    $orphan = Node::create(['space_id' => $space->id, 'title' => 'Orphan', 'depth' => 0]);
    $orphanKid = Node::create(['space_id' => $space->id, 'title' => 'OrphanKid', 'depth' => 1]);
    Edge::create(['space_id' => $space->id, 'parent_id' => $orphan->id, 'child_id' => $orphanKid->id]);

    // Подвешиваем деревце под child: оно должно съехать на уровень ниже целиком.
    $this->postJson("/api/spaces/{$space->id}/links", [
        'parent_id' => $child->id,
        'child_id' => $orphan->id,
    ])->assertStatus(201);

    expect($orphan->fresh()->depth)->toBe(2);
    expect($orphanKid->fresh()->depth)->toBe(3);
});

test('switching to leveled repairs depths but refuses real level gaps', function () {
    $repo = new GraphRepository;
    $space = Space::create(['name' => 'Repair', 'slug' => 'repair', 'structure' => Space::STRUCTURE_DAG]);

    // Глубины намеренно проставлены неверно — пересчёт от корня их починит.
    $root = Node::create(['space_id' => $space->id, 'title' => 'Root', 'depth' => 7]);
    $child = Node::create(['space_id' => $space->id, 'title' => 'Child', 'depth' => 7]);
    Edge::create(['space_id' => $space->id, 'parent_id' => $root->id, 'child_id' => $child->id]);

    $this->putJson("/api/spaces/{$space->id}/structure", [
        'structure' => Space::STRUCTURE_LEVELED,
    ])->assertStatus(200);

    expect($root->fresh()->depth)->toBe(0);
    expect($child->fresh()->depth)->toBe(1);

    // Ромб: до узла есть пути разной длины, уровни не расставить без фиктивных узлов.
    $diamond = Space::create(['name' => 'Diamond', 'slug' => 'diamond', 'structure' => Space::STRUCTURE_DAG]);
    $a = Node::create(['space_id' => $diamond->id, 'title' => 'A']);
    $b = Node::create(['space_id' => $diamond->id, 'title' => 'B']);
    $c = Node::create(['space_id' => $diamond->id, 'title' => 'C']);
    Edge::create(['space_id' => $diamond->id, 'parent_id' => $a->id, 'child_id' => $b->id]);
    Edge::create(['space_id' => $diamond->id, 'parent_id' => $b->id, 'child_id' => $c->id]);
    Edge::create(['space_id' => $diamond->id, 'parent_id' => $a->id, 'child_id' => $c->id]);

    $refused = $this->putJson("/api/spaces/{$diamond->id}/structure", [
        'structure' => Space::STRUCTURE_LEVELED,
    ]);
    $refused->assertStatus(422);
    expect($refused->json('reason'))->toBe('level_gap');
    expect($refused->json('conflicts'))->toBe(1);
    expect($diamond->fresh()->structure)->toBe(Space::STRUCTURE_DAG);

    expect($repo->levelGaps($diamond->id, $repo->computeLevelDepths($diamond->id)))->toBe(1);
});
