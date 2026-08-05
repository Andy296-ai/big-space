<?php

use App\Models\Node;
use App\Models\Space;
use App\Models\User;
use Tests\TestCase;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->admin = Space::where('is_admin', true)->firstOrFail();
});

function createMirroredUserAndSpace(TestCase $test, string $slug): array
{
    $created = $test->postJson('/api/admin/users', [
        'name' => "User {$slug}",
        'username' => "user-{$slug}",
        'email' => "{$slug}@example.com",
        'password' => 'password1234',
    ])->json();

    $user = User::findOrFail($created['user']['id']);
    $userNodeId = $created['node']['id'];

    $test->actingAs($user);
    $space = $test->postJson('/api/spaces', ['name' => "Space {$slug}"])->json();

    // Дальше по умолчанию действуем от root — большинству вызывающих тестов
    // это и нужно (управление узлами в Admin-пространстве).
    $test->actingAs(User::where('is_root', true)->firstOrFail());

    return [$user, $userNodeId, $space];
}

test('a user deleting their own space also removes its mirror node in the admin space', function () {
    $this->actingAs($this->root);
    [$user, , $space] = createMirroredUserAndSpace($this, 'a');

    $extra = $this->actingAs($user)->postJson('/api/spaces', ['name' => 'Second'])->json();
    $spaceNode = Node::where('linked_space_id', $space['id'])->firstOrFail();

    $this->actingAs($user)->deleteJson("/api/spaces/{$space['id']}")->assertStatus(200);

    $this->assertDatabaseMissing('nodes', ['id' => $spaceNode->id]);
    $this->assertDatabaseMissing('spaces', ['id' => $space['id']]);
    expect($extra)->toBeArray(); // sanity: the second space exists so deletion above was legal
});

test('deleting a space-mirror node in the admin space deletes the real space too', function () {
    $this->actingAs($this->root);
    [$user, , $space] = createMirroredUserAndSpace($this, 'b');
    $this->actingAs($user)->postJson('/api/spaces', ['name' => 'Keep me']);
    $this->actingAs($this->root);

    $spaceNode = Node::where('linked_space_id', $space['id'])->firstOrFail();

    $this->postJson("/api/spaces/{$this->admin->id}/nodes/delete-many", [
        'ids' => [$spaceNode->id],
    ])->assertStatus(200);

    $this->assertDatabaseMissing('nodes', ['id' => $spaceNode->id]);
    $this->assertDatabaseMissing('spaces', ['id' => $space['id']]);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('deleting a space-mirror node for someone\'s only space is refused', function () {
    $this->actingAs($this->root);
    [, , $space] = createMirroredUserAndSpace($this, 'c');

    $spaceNode = Node::where('linked_space_id', $space['id'])->firstOrFail();

    $this->postJson("/api/spaces/{$this->admin->id}/nodes/delete-many", [
        'ids' => [$spaceNode->id],
    ])->assertStatus(422);

    $this->assertDatabaseHas('nodes', ['id' => $spaceNode->id]);
    $this->assertDatabaseHas('spaces', ['id' => $space['id']]);
});

test('deleting a user node (and its subtree) deletes the user but leaves their spaces ownerless', function () {
    $this->actingAs($this->root);
    [$user, $userNodeId, $space] = createMirroredUserAndSpace($this, 'd');

    $this->postJson("/api/spaces/{$this->admin->id}/nodes/delete-many", [
        'ids' => [$userNodeId],
    ])->assertStatus(200);

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('nodes', ['id' => $userNodeId]);
    $this->assertDatabaseHas('spaces', ['id' => $space['id'], 'user_id' => null]);
});

test('undoing a space-mirror-node deletion restores the node without a dangling reference', function () {
    $this->actingAs($this->root);
    [$user, , $space] = createMirroredUserAndSpace($this, 'e');
    $this->actingAs($user)->postJson('/api/spaces', ['name' => 'Keep me too']);
    $this->actingAs($this->root);

    $spaceNode = Node::where('linked_space_id', $space['id'])->firstOrFail();

    $deleted = $this->postJson("/api/spaces/{$this->admin->id}/nodes/delete-many", [
        'ids' => [$spaceNode->id],
    ])->assertStatus(200)->json();

    $this->postJson("/api/spaces/{$this->admin->id}/nodes/restore", [
        'undo_token' => $deleted['undo_token'],
    ])->assertStatus(200);

    $this->assertDatabaseHas('nodes', ['id' => $spaceNode->id, 'linked_space_id' => null]);
    $this->assertDatabaseMissing('spaces', ['id' => $space['id']]);
});
