<?php

use App\Models\Node;
use App\Models\Space;
use App\Models\User;

/**
 * Регрессия на IDOR: раньше несколько методов GraphController не проверяли,
 * что переданный {node} действительно принадлежит {space} из URL — Laravel
 * резолвит route-model-binding по ID глобально, не привязывая к соседнему
 * сегменту маршрута. Каждый тест — злоумышленник с правами editor только в
 * СВОЁМ пространстве пытается прочитать/поменять/скопировать чужой узел,
 * подставив ID чужого узла вместе с ID своего пространства в URL.
 */
beforeEach(function () {
    $this->victim = User::factory()->create();
    $this->actingAs($this->victim);
    $this->victimSpace = Space::create(['name' => 'Victim Space', 'slug' => 'victim-space', 'user_id' => $this->victim->id]);
    $this->victimNode = Node::create([
        'space_id' => $this->victimSpace->id,
        'title' => 'Secret Victim Node',
        'description' => 'Confidential',
        'depth' => 0,
    ]);

    $this->attacker = User::factory()->create();
    $this->actingAs($this->attacker);
    $this->attackerSpace = Space::create(['name' => 'Attacker Space', 'slug' => 'attacker-space', 'user_id' => $this->attacker->id]);
    $this->attackerNode = Node::create([
        'space_id' => $this->attackerSpace->id,
        'title' => 'Attacker Own Node',
        'depth' => 0,
    ]);

    $this->actingAs($this->attacker);
});

test('cannot copy a node from a space you do not own', function () {
    $this->postJson("/api/spaces/{$this->attackerSpace->id}/nodes/{$this->victimNode->id}/copy")
        ->assertStatus(404);

    $this->assertDatabaseMissing('nodes', ['space_id' => $this->attackerSpace->id, 'title' => 'Secret Victim Node']);
});

test('cannot move a node from a space you do not own', function () {
    $this->putJson("/api/spaces/{$this->attackerSpace->id}/nodes/{$this->victimNode->id}/move", [
        'pos_x' => 999,
        'pos_y' => 999,
    ])->assertStatus(404);

    $this->assertDatabaseMissing('nodes', ['id' => $this->victimNode->id, 'pos_x' => 999]);
});

test('cannot overwrite a node from a space you do not own', function () {
    $this->putJson("/api/spaces/{$this->attackerSpace->id}/nodes/{$this->victimNode->id}", [
        'title' => 'Pwned',
    ])->assertStatus(404);

    $this->assertDatabaseHas('nodes', ['id' => $this->victimNode->id, 'title' => 'Secret Victim Node']);
});

test('cannot add a child under a parent node from a space you do not own', function () {
    $this->postJson("/api/spaces/{$this->attackerSpace->id}/nodes/{$this->victimNode->id}/child", [
        'title' => 'Injected Child',
    ])->assertStatus(404);

    $this->assertDatabaseMissing('edges', ['parent_id' => $this->victimNode->id]);
});

test('cannot preview the deletion set of a node from a space you do not own', function () {
    $this->getJson("/api/spaces/{$this->attackerSpace->id}/nodes/{$this->victimNode->id}/deletion-preview")
        ->assertStatus(404);
});

test('cannot link a node from a space you do not own into your own space', function () {
    $this->postJson("/api/spaces/{$this->attackerSpace->id}/links", [
        'parent_id' => $this->attackerNode->id,
        'child_id' => $this->victimNode->id,
    ])->assertStatus(422);

    $this->assertDatabaseMissing('edges', ['parent_id' => $this->attackerNode->id, 'child_id' => $this->victimNode->id]);
});

test('unlink does not leak a foreign node\'s title into your own activity log', function () {
    $response = $this->deleteJson("/api/spaces/{$this->attackerSpace->id}/links", [
        'parent_id' => $this->attackerNode->id,
        'child_id' => $this->victimNode->id,
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseMissing('activity_logs', ['meta->child_title' => 'Secret Victim Node']);
});
