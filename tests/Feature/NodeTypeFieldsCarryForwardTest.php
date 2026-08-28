<?php

use App\Models\Node;
use App\Models\NodeRevision;
use App\Models\NodeType;
use App\Models\Space;
use App\Models\User;

/**
 * Узел с типом/кастомными полями участвует в пяти операциях, каждая из
 * которых хранит/копирует поля узла НЕЗАВИСИМО (см. план "Flexible node
 * types" — почему это отдельная точка риска, не просто побочный эффект
 * create/update): создание+редактирование, история правок (откат),
 * удаление+отмена, копирование поддерева. Пропуск любой из них выглядел бы
 * как готовая фича, которая молча теряет типизацию/данные на этой операции.
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->space = Space::create(['name' => 'Carry Forward', 'slug' => 'carry-forward', 'user_id' => $this->owner->id]);
    $this->actingAs($this->owner);

    $this->contact = NodeType::create([
        'space_id' => $this->space->id,
        'key' => 'contact',
        'label' => 'Contact',
        'field_schema' => [
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
        ],
    ]);
});

test('creating a root node with a type persists its custom fields', function () {
    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/root", [
        'title' => 'Ada Lovelace',
        'node_type_id' => $this->contact->id,
        'custom_fields' => ['phone' => '+1 555 0100', 'notes' => 'met at a conference'],
    ])->assertStatus(201)->json();

    expect($response['node_type_id'])->toBe($this->contact->id);
    expect($response['custom_fields'])->toBe(['phone' => '+1 555 0100', 'notes' => 'met at a conference']);
});

test('a missing required custom field is rejected on create', function () {
    $this->postJson("/api/spaces/{$this->space->id}/nodes/root", [
        'title' => 'No phone',
        'node_type_id' => $this->contact->id,
        'custom_fields' => ['notes' => 'incomplete'],
    ])->assertStatus(422);
});

test('a relation field must point at a node in the same space', function () {
    $task = NodeType::create([
        'space_id' => $this->space->id,
        'key' => 'task',
        'label' => 'Task',
        'field_schema' => [['key' => 'assignee', 'label' => 'Assignee', 'type' => 'relation']],
    ]);
    $foreignSpace = Space::create(['name' => 'Foreign', 'slug' => 'foreign-space', 'user_id' => $this->owner->id]);
    $foreignNode = Node::create(['space_id' => $foreignSpace->id, 'title' => 'Not in this space']);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/root", [
        'title' => 'Ship it',
        'node_type_id' => $task->id,
        'custom_fields' => ['assignee' => $foreignNode->id],
    ])->assertStatus(422);
});

test('switching a node to a different type drops custom fields that no longer belong', function () {
    $node = Node::create([
        'space_id' => $this->space->id,
        'title' => 'Ada',
        'node_type_id' => $this->contact->id,
        'custom_fields' => ['phone' => '+1 555 0100', 'notes' => 'x'],
    ]);
    $plainType = NodeType::create([
        'space_id' => $this->space->id,
        'key' => 'plain',
        'label' => 'Plain',
        'field_schema' => [['key' => 'summary', 'label' => 'Summary', 'type' => 'text']],
    ]);

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$node->id}", [
        'title' => 'Ada',
        'node_type_id' => $plainType->id,
        'custom_fields' => ['summary' => 'now a plain node', 'phone' => 'stale, should be dropped'],
    ])->assertStatus(200);

    $fresh = $node->fresh();
    expect($fresh->node_type_id)->toBe($plainType->id);
    expect($fresh->custom_fields)->toBe(['summary' => 'now a plain node']);
});

test('editing a custom field is undoable through node history', function () {
    $node = Node::create([
        'space_id' => $this->space->id,
        'title' => 'Ada',
        'node_type_id' => $this->contact->id,
        'custom_fields' => ['phone' => 'original number'],
    ]);

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$node->id}", [
        'title' => 'Ada',
        'node_type_id' => $this->contact->id,
        'custom_fields' => ['phone' => 'changed number'],
    ])->assertStatus(200);

    expect($node->fresh()->custom_fields)->toBe(['phone' => 'changed number']);

    $revision = NodeRevision::where('node_id', $node->id)->orderByDesc('id')->first();
    expect($revision->custom_fields)->toBe(['phone' => 'original number']);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$node->id}/revisions/{$revision->id}/restore")
        ->assertStatus(200);

    expect($node->fresh()->custom_fields)->toBe(['phone' => 'original number']);
});

test('deleting a typed node and undoing the deletion keeps it typed', function () {
    $node = Node::create([
        'space_id' => $this->space->id,
        'title' => 'Ada',
        'node_type_id' => $this->contact->id,
        'custom_fields' => ['phone' => '+1 555 0100'],
    ]);

    $deleteRes = $this->postJson("/api/spaces/{$this->space->id}/nodes/delete-many", ['ids' => [$node->id]])
        ->assertStatus(200);
    $this->assertDatabaseMissing('nodes', ['id' => $node->id]);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/restore", ['undo_token' => $deleteRes->json('undo_token')])
        ->assertStatus(200);

    $restored = Node::find($node->id);
    expect($restored->node_type_id)->toBe($this->contact->id);
    expect($restored->custom_fields)->toBe(['phone' => '+1 555 0100']);
});

test('copying a typed node produces a typed copy with the same custom fields', function () {
    $node = Node::create([
        'space_id' => $this->space->id,
        'title' => 'Ada',
        'node_type_id' => $this->contact->id,
        'custom_fields' => ['phone' => '+1 555 0100'],
    ]);

    $copy = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$node->id}/copy", [])
        ->assertStatus(201)
        ->json();

    expect($copy['node_type_id'])->toBe($this->contact->id);
    expect($copy['custom_fields'])->toBe(['phone' => '+1 555 0100']);
});
