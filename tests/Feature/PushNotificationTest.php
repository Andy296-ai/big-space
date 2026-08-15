<?php

use App\Models\Conversation;
use App\Models\User;
use App\Notifications\MessagePushNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->author = User::factory()->create();
    $this->global->participants()->attach($this->author->id);
});

test('sending a message pushes a notification to every other participant, not the sender', function () {
    Notification::fake();

    $this->actingAs($this->author);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'hello team'])
        ->assertStatus(201);

    Notification::assertSentTo($this->root, MessagePushNotification::class);
    Notification::assertNotSentTo($this->author, MessagePushNotification::class);
});

test('editing or deleting a message does not trigger a push notification', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'v1'])->json();

    Notification::fake();

    $this->putJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}", ['body' => 'v2']);
    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}");

    Notification::assertNothingSent();
});

test('a user can subscribe and unsubscribe their own push endpoint', function () {
    $this->actingAs($this->author);

    $this->postJson('/api/push/subscribe', [
        'endpoint' => 'https://push.example.com/abc',
        'keys' => ['p256dh' => 'pubkey', 'auth' => 'authtoken'],
    ])->assertStatus(201);

    $this->assertDatabaseHas('push_subscriptions', [
        'endpoint' => 'https://push.example.com/abc',
        'subscribable_id' => $this->author->id,
        'subscribable_type' => User::class,
    ]);

    $this->postJson('/api/push/unsubscribe', ['endpoint' => 'https://push.example.com/abc'])
        ->assertStatus(200);

    $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://push.example.com/abc']);
});

test('a failure while sending push notifications does not break posting the message itself', function () {
    Notification::shouldReceive('send')->andThrow(new RuntimeException('push provider unreachable'));

    $this->actingAs($this->author);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'still works'])
        ->assertStatus(201);

    $this->assertDatabaseHas('messages', ['body' => 'still works']);
});
