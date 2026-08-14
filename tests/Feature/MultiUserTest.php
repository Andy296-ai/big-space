<?php

use App\Models\Node;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
});

test('the seeded root user is flagged and owns the admin space', function () {
    expect($this->root->is_root)->toBeTrue();

    $admin = Space::where('is_admin', true)->firstOrFail();
    expect($admin->user_id)->toBe($this->root->id);
    expect($admin->slug)->toBe('admin');
});

test('root can create a user, which appears as a node in the admin space', function () {
    $this->actingAs($this->root);
    $admin = Space::where('is_admin', true)->firstOrFail();

    $response = $this->postJson('/api/admin/users', [
        'name' => 'Ада Лавлейс',
        'username' => 'ada',
        'email' => 'ada@example.com',
        'password' => 'correct horse battery staple',
        'shape' => 'square',
    ]);

    $response->assertStatus(201);

    $userId = $response->json('user.id');
    $nodeId = $response->json('node.id');

    $this->assertDatabaseHas('users', ['id' => $userId, 'name' => 'ada', 'is_root' => false]);
    $this->assertDatabaseHas('nodes', [
        'id' => $nodeId,
        'space_id' => $admin->id,
        'title' => 'Ада Лавлейс',
        'linked_user_id' => $userId,
        'shape' => 'square',
        'depth' => 0,
    ]);
});

test('root can reset a user\'s password and they can log in with it', function () {
    $this->actingAs($this->root);

    $created = $this->postJson('/api/admin/users', [
        'name' => 'Ada Lovelace',
        'username' => 'ada2',
        'email' => 'ada2@example.com',
        'password' => 'the original password',
    ])->json();

    $this->putJson("/api/admin/users/{$created['user']['id']}/password", [
        'password' => 'a brand new password',
    ])->assertStatus(200);

    $this->post('/logout');

    // Дальше — второй фактор (см. TwoFactorLoginTest.php), здесь проверяем
    // только то, что относится к самому сбросу пароля: новый пароль проходит
    // проверку учётных данных (шаг 1), а не блокируется как неверный.
    $response = $this->post('/login', [
        'username' => 'ada2',
        'password' => 'a brand new password',
    ]);

    $response->assertRedirect('/login');
    $this->assertGuest();
    $this->assertDatabaseHas('login_verification_codes', ['user_id' => $created['user']['id']]);
});

test('a password reset must be at least 9 characters', function () {
    $this->actingAs($this->root);

    $created = $this->postJson('/api/admin/users', [
        'name' => 'Ada Lovelace',
        'username' => 'ada3',
        'email' => 'ada3@example.com',
        'password' => 'the original password',
    ])->json();

    $this->putJson("/api/admin/users/{$created['user']['id']}/password", [
        'password' => '12345678',
    ])->assertStatus(422);
});

test('a non-root user cannot reset anyone\'s password', function () {
    $stranger = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($stranger);

    $this->putJson("/api/admin/users/{$other->id}/password", [
        'password' => 'a brand new password',
    ])->assertStatus(403);
});

test('a non-root user cannot manage users', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $this->postJson('/api/admin/users', [
        'name' => 'Someone',
        'username' => 'someone',
        'email' => 'someone@example.com',
        'password' => 'password1234',
    ])->assertStatus(403);
});

test('the root user cannot be deleted', function () {
    $this->actingAs($this->root);

    $this->deleteJson("/api/admin/users/{$this->root->id}")->assertStatus(403);
    $this->assertDatabaseHas('users', ['id' => $this->root->id]);
});

test('deleting a user removes their admin node but leaves their spaces ownerless, not gone', function () {
    $this->actingAs($this->root);

    $created = $this->postJson('/api/admin/users', [
        'name' => 'Grace Hopper',
        'username' => 'grace',
        'email' => 'grace@example.com',
        'password' => 'password1234',
    ])->json();

    $user = User::findOrFail($created['user']['id']);
    $this->actingAs($user);
    $space = $this->postJson('/api/spaces', ['name' => 'Grace Space'])->json();

    $this->actingAs($this->root);
    $this->deleteJson("/api/admin/users/{$user->id}")->assertStatus(200);

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('nodes', ['id' => $created['node']['id']]);
    $this->assertDatabaseHas('spaces', ['id' => $space['id'], 'user_id' => null]);
});

test('creating a space links a child node under the owner in the admin space', function () {
    $this->actingAs($this->root);

    $created = $this->postJson('/api/admin/users', [
        'name' => 'Alan Turing',
        'username' => 'alan',
        'email' => 'alan@example.com',
        'password' => 'password1234',
    ])->json();

    $user = User::findOrFail($created['user']['id']);
    $userNodeId = $created['node']['id'];

    $this->actingAs($user);
    $space = $this->postJson('/api/spaces', ['name' => 'Enigma'])->json();

    $this->assertDatabaseHas('nodes', [
        'linked_space_id' => $space['id'],
        'title' => 'Enigma',
    ]);

    $spaceNode = Node::where('linked_space_id', $space['id'])->firstOrFail();
    $this->assertDatabaseHas('edges', [
        'parent_id' => $userNodeId,
        'child_id' => $spaceNode->id,
    ]);
});

test('a regular user only sees their own spaces, never the admin space or another user\'s', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $this->actingAs($alice);
    $aliceSpace = $this->postJson('/api/spaces', ['name' => 'Alice Space'])->json();

    $this->actingAs($bob);
    $this->postJson('/api/spaces', ['name' => 'Bob Space'])->assertStatus(201);

    $response = $this->getJson('/api/spaces');
    $slugs = collect($response->json())->pluck('name');

    expect($slugs)->toContain('Bob Space');
    expect($slugs)->not->toContain('Alice Space');
    expect($slugs)->not->toContain('Admin');

    // И достучаться до чужого пространства напрямую тоже нельзя.
    $this->getJson("/api/spaces/{$aliceSpace['id']}/graph")->assertStatus(403);
});

test('root can access any space, including another user\'s and the admin space', function () {
    $alice = User::factory()->create();
    $this->actingAs($alice);
    $aliceSpace = $this->postJson('/api/spaces', ['name' => 'Alice Space'])->json();

    $this->actingAs($this->root);
    $this->getJson("/api/spaces/{$aliceSpace['id']}/graph")->assertStatus(200);

    $admin = Space::where('is_admin', true)->firstOrFail();
    $this->getJson("/api/spaces/{$admin->id}/graph")->assertStatus(200);
});

test('the admin space itself cannot be deleted', function () {
    $this->actingAs($this->root);
    $admin = Space::where('is_admin', true)->firstOrFail();

    $this->deleteJson("/api/spaces/{$admin->id}")->assertStatus(422);
    $this->assertDatabaseHas('spaces', ['id' => $admin->id]);
});

test('node shape must be one of the known shapes', function () {
    $this->actingAs($this->root);
    $space = $this->postJson('/api/spaces', ['name' => 'Shapes'])->json();

    $this->postJson("/api/spaces/{$space['id']}/nodes/root", [
        'title' => 'Bad shape',
        'shape' => 'octagon',
    ])->assertStatus(422);

    $this->postJson("/api/spaces/{$space['id']}/nodes/root", [
        'title' => 'Good shape',
        'shape' => 'triangle',
    ])->assertStatus(201)
        ->assertJson(['shape' => 'triangle']);
});
