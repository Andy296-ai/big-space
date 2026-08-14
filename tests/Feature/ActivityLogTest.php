<?php

use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
});

test('creating, resetting the password of, and deleting a user each leave a trail', function () {
    $this->actingAs($this->root);

    $created = $this->postJson('/api/admin/users', [
        'name' => 'Ada Lovelace',
        'username' => 'ada-log',
        'email' => 'ada-log@example.com',
        'password' => 'the original password',
    ])->json();
    $userId = $created['user']['id'];

    $this->putJson("/api/admin/users/{$userId}/password", [
        'password' => 'a brand new password',
    ])->assertStatus(200);

    $this->deleteJson("/api/admin/users/{$userId}")->assertStatus(200);

    // Само чтение лога тоже пишет запись (activity_log.viewed) — отсеиваем
    // её, тест проверяет только бизнес-события.
    $entries = collect($this->getJson('/api/admin/activity-log')->assertStatus(200)->json('entries'))
        ->reject(fn ($e) => $e['action'] === 'activity_log.viewed')
        ->values();
    $actions = $entries->pluck('action')->take(3)->all();

    // Most recent first.
    expect($actions)->toBe([
        'user.deleted',
        'user.password_reset',
        'user.created',
    ]);
    expect($entries[0]['actor']['id'])->toBe($this->root->id);
    expect($entries[0]['subject_id'])->toBe($userId);
    expect($entries[0]['meta']['email'])->toBe('ada-log@example.com');
});

test('root deleting someone else\'s space is logged, a user deleting their own is not', function () {
    $alice = User::factory()->create();
    $this->actingAs($alice);
    $aliceSpace = $this->postJson('/api/spaces', ['name' => 'Alice Space'])->json();
    // A second space so the first one isn't "the only one" and can be deleted.
    $this->postJson('/api/spaces', ['name' => 'Alice Space 2']);

    $this->deleteJson("/api/spaces/{$aliceSpace['id']}")->assertStatus(200);

    $this->actingAs($this->root);
    $rootSpace = $this->postJson('/api/spaces', ['name' => 'Root Extra 1'])->json();
    $this->postJson('/api/spaces', ['name' => 'Root Extra 2']);
    $this->deleteJson("/api/spaces/{$rootSpace['id']}")->assertStatus(200);

    $entries = collect($this->getJson('/api/admin/activity-log')->assertStatus(200)->json('entries'))
        ->reject(fn ($e) => $e['action'] === 'activity_log.viewed')
        ->values();

    expect($entries->where('action', 'space.deleted')->count())->toBe(1);
    expect($entries[0]['meta']['name'])->toBe('Root Extra 1');
});

test('a non-root user cannot read the activity log', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $this->getJson('/api/admin/activity-log')->assertStatus(403);
});

test('the log survives the deleted user it refers to', function () {
    $this->actingAs($this->root);

    $created = $this->postJson('/api/admin/users', [
        'name' => 'Grace Hopper',
        'username' => 'grace-log',
        'email' => 'grace-log@example.com',
        'password' => 'password1234',
    ])->json();

    $this->deleteJson("/api/admin/users/{$created['user']['id']}")->assertStatus(200);

    $this->assertDatabaseMissing('users', ['id' => $created['user']['id']]);
    $this->assertDatabaseHas('activity_logs', [
        'subject_id' => $created['user']['id'],
        'action' => 'user.deleted',
    ]);
});
