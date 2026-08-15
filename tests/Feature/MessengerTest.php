<?php

use App\Events\MessagePosted;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
});

test('root can post and read a text message in the global conversation', function () {
    $this->actingAs($this->root);

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'Всем привет'])
        ->assertStatus(201)
        ->assertJsonPath('body', 'Всем привет')
        ->assertJsonPath('type', 'text');

    $entries = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages")->json();
    expect($entries)->toHaveCount(1);
    expect($entries[0]['body'])->toBe('Всем привет');
});

test('a user created via the admin panel is automatically a global-conversation participant', function () {
    $this->actingAs($this->root);

    $created = $this->postJson('/api/admin/users', [
        'name' => 'Ada Lovelace',
        'username' => 'ada-msg',
        'email' => 'ada-msg@example.com',
        'password' => 'a genuinely long password',
    ])->json();

    $ada = User::findOrFail($created['user']['id']);
    $this->actingAs($ada);

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'привет от Ады'])
        ->assertStatus(201);
});

test('a user not attached to the global conversation cannot read or post into it', function () {
    // Создан напрямую фабрикой — минуя Admin\UserController::store, значит
    // без строки, привязывающей к глобальному разговору. Это регрессия на
    // IDOR-класс уязвимости, найденный ранее в GraphController: попытка
    // достучаться до чужого/недоступного ресурса по угаданному id.
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $this->getJson("/api/messenger/conversations/{$this->global->id}/messages")->assertStatus(403);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'intruder'])
        ->assertStatus(403);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/read")->assertStatus(403);
});

test('a whitespace-only message body is rejected', function () {
    $this->actingAs($this->root);

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => '   '])
        ->assertStatus(422);
});

test('posting a message dispatches a signal to every other participant, but not the sender', function () {
    Event::fake([MessagePosted::class]);

    $this->actingAs($this->root);
    $created = $this->postJson('/api/admin/users', [
        'name' => 'Grace Hopper',
        'username' => 'grace-msg',
        'email' => 'grace-msg@example.com',
        'password' => 'a genuinely long password',
    ])->json();
    $grace = User::findOrFail($created['user']['id']);

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'hello'])
        ->assertStatus(201);

    Event::assertDispatched(MessagePosted::class, function (MessagePosted $event) use ($grace) {
        return $event->conversationId === $this->global->id
            && in_array($grace->id, $event->recipientUserIds, true)
            && ! in_array($this->root->id, $event->recipientUserIds, true);
    });
});

test('unread count reflects messages posted since the last read, and drops to zero after marking read', function () {
    $this->actingAs($this->root);
    $created = $this->postJson('/api/admin/users', [
        'name' => 'Alan Turing',
        'username' => 'alan-msg',
        'email' => 'alan-msg@example.com',
        'password' => 'a genuinely long password',
    ])->json();
    $alan = User::findOrFail($created['user']['id']);

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'from root']);

    $this->actingAs($alan);
    $summary = $this->getJson('/api/messenger/summary')->json();
    $global = collect($summary['conversations'])->firstWhere('id', $this->global->id);
    expect($global['unread_count'])->toBe(1);
    expect($summary['total_unread'])->toBe(1);

    $this->postJson("/api/messenger/conversations/{$this->global->id}/read")->assertStatus(200);

    $summary = $this->getJson('/api/messenger/summary')->json();
    $global = collect($summary['conversations'])->firstWhere('id', $this->global->id);
    expect($global['unread_count'])->toBe(0);
});

test('a message you sent yourself never counts toward your own unread total', function () {
    $this->actingAs($this->root);

    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'self talk']);

    $summary = $this->getJson('/api/messenger/summary')->json();
    $global = collect($summary['conversations'])->firstWhere('id', $this->global->id);
    expect($global['unread_count'])->toBe(0);
});

test('message pagination returns the newest page in chronological order and supports before_id', function () {
    $this->actingAs($this->root);

    foreach (range(1, 5) as $i) {
        $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => "msg {$i}"]);
    }

    $page = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages?limit=2")->json();
    expect(collect($page)->pluck('body')->all())->toBe(['msg 4', 'msg 5']);

    $olderPage = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages?limit=2&before_id={$page[0]['id']}")->json();
    expect(collect($olderPage)->pluck('body')->all())->toBe(['msg 2', 'msg 3']);
});
