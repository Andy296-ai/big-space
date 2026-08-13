<?php

use App\Events\NotificationPosted;
use App\Models\Space;
use App\Models\User;
use App\Notifications\SpaceAccessGranted;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->friend = User::factory()->create(['name' => 'grantee']);

    $this->actingAs($this->owner);
    $this->space = Space::create(['name' => 'Granted', 'slug' => 'granted-space', 'user_id' => $this->owner->id]);
});

test('granting access notifies the recipient and broadcasts a live signal', function () {
    Event::fake([NotificationPosted::class]);

    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'grantee',
        'role' => 'editor',
    ])->assertStatus(201);

    $this->friend->refresh();
    expect($this->friend->notifications)->toHaveCount(1);

    $notification = $this->friend->notifications->first();
    expect($notification->type)->toBe(SpaceAccessGranted::class);
    expect($notification->data['space_id'])->toBe($this->space->id);
    expect($notification->data['space_slug'])->toBe('granted-space');
    expect($notification->data['space_name'])->toBe('Granted');
    expect($notification->data['role'])->toBe('editor');
    expect($notification->data['owner_name'])->toBe($this->owner->name);
    expect($notification->read_at)->toBeNull();

    Event::assertDispatched(NotificationPosted::class, fn (NotificationPosted $e) => $e->userId === $this->friend->id);
});

test('the recipient can list, read, and mark all of their notifications as read', function () {
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", [
        'identifier' => 'grantee',
        'role' => 'viewer',
    ]);

    $this->actingAs($this->friend);

    $listed = $this->getJson('/api/notifications')->assertStatus(200)->json();
    expect($listed['unread_count'])->toBe(1);
    expect($listed['notifications'])->toHaveCount(1);

    $id = $listed['notifications'][0]['id'];

    $this->postJson("/api/notifications/{$id}/read")->assertStatus(200);
    expect($this->getJson('/api/notifications')->json('unread_count'))->toBe(0);
});

test('mark-all-read clears every unread notification for the current user', function () {
    $second = Space::create(['name' => 'Granted 2', 'slug' => 'granted-space-2', 'user_id' => $this->owner->id]);

    $this->postJson("/api/spaces/{$this->space->id}/collaborators", ['identifier' => 'grantee', 'role' => 'viewer']);
    $this->postJson("/api/spaces/{$second->id}/collaborators", ['identifier' => 'grantee', 'role' => 'viewer']);

    $this->actingAs($this->friend);
    expect($this->getJson('/api/notifications')->json('unread_count'))->toBe(2);

    $this->postJson('/api/notifications/read-all')->assertStatus(200);
    expect($this->getJson('/api/notifications')->json('unread_count'))->toBe(0);
});

test('a user cannot mark someone else\'s notification as read', function () {
    $this->postJson("/api/spaces/{$this->space->id}/collaborators", ['identifier' => 'grantee', 'role' => 'viewer']);

    $this->friend->refresh();
    $id = $this->friend->notifications->first()->id;

    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $this->postJson("/api/notifications/{$id}/read")->assertStatus(403);
});
